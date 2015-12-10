<?php
// SMS Libs
require("../libs/sms/SmsReceiver.php");
require("../libs/sms/SmsSender.php");
include_once '../log.php';
ini_set('error_log', 'sms-app-error.log');

// LBS Libs
require("../libs/lbs/LbsClient.php");
require("../libs/lbs/LbsRequest.php");
require("../libs/lbs/LbsResponse.php");

// Config File
require('../config/appconfig.php');

// DB Lib
require("../libs/db/db.php");

// Distance Code
require("../libs/distance.php");

// Action Find
try{
    $pdo = openConnection($HOST, $DB, $USERNAME, $PASSWORD);

    $receiver = new SmsReceiver();

    $message = $receiver->getMessage();
    $mask = $receiver->getAddress();

    $info = explode(' ', $message);
    $SUB_ID = "94771122336";

    switch (strtoupper($info[1])) {
        case "SU":
            $reply = signUp($mask, $info, $pdo, getLoc($LBS_URL, $APP_INFO, $SUB_ID));
            break;
        case "HELP":
            $reply = help();
            break;
        case "LU":
            $reply = lookUp(getLoc($LBS_URL, $APP_INFO, $SUB_ID), $mask, $pdo, $SENDER_URL, $APP_INFO);
            break;
        case "BZ":
            $smask = getFMask($mask, $pdo);
            if( $smask == 'nill'){
                $txt = "You are not connected to anyone to BUZZ. Please try Looking Up for friends.";
                $smask = $mask;
            }else{
                $txt = "Your Friendstr is BUZZING you. Reply Back!";
            }
            $reply = sendReply($txt, $smask, $SENDER_URL, $APP_INFO);
            break;
        case "M":
            $txt = substr($message, 7);
            $smask = getFMask($mask, $pdo);
            if( $smask == 'nill'){
                $txt = "You are not connected to anyone to send this message. Please try Looking Up for friends.";
                $smask = $mask;
            }
            $reply = sendReply($txt, $smask, $SENDER_URL, $APP_INFO);
            break;
        case "EXIT":
            sendReply("Your friend has left the conversation.", getFMask($mask, $pdo), $SENDER_URL, $APP_INFO);
            $reply = exitCoversation($mask, $pdo);
            break;
        default:
            break;
    }

    sendReply($reply, $mask, $SENDER_URL, $APP_INFO);

} catch (SmsException $ex) {
    error_log("ERROR: {$ex->getStatusCode()} | {$ex->getStatusMessage()}");
}

function lookUp($loc, $mask, $pdo, $SENDER_URL, $APP_INFO){
    $user = getUser($mask, $pdo);
    $list = getMatchList($mask, $pdo);
    if(getUser($mask, $pdo)["friendMask"] != 'nill'){
        sendReply("Your friend has left the conversation.", getFMask($mask, $pdo), $SENDER_URL, $APP_INFO);
        sendReply(exitCoversation($mask, $pdo), $mask, $SENDER_URL, $APP_INFO);
    }
    $found = FALSE;
    foreach($list as $listUser){
        if(distance($listUser["lat"], $listUser["longi"], $user["lat"], $user["longi"], "K") <= 10){
            updateFriend($mask, $listUser["mask"], $pdo);
            updateFriend($listUser["mask"], $mask, $pdo);
            $user = $listUser["username"];
            $found = TRUE;
            break;
        }
    }
    if($found){
        return "You have been matched up with $user.\n Type FSTR M <message> and send it to 77000 to start the conversation.";
    }else{
        return "Sorry no match found, please try again later.";
    }
}

function help(){
    $msg = "Friendstr Help.\n";
    $msg .= "FSTR LU - Connect a new friend.\n";
    $msg .= "FSTR BZ - Buzz friend.\n";
    $msg .= "FSTR M - Message friend.\n";
    $msg .= "EXIT - Exit conversation.";
    return $msg;
}

function getFMask($mask, $pdo){
    $sql = "SELECT friendMask FROM userinfo WHERE mask = :mask";
    $query = $pdo->prepare($sql);
    $query->execute(
        array(
            ':mask' => $mask
        )
    );
    $result = $query->fetch();
    return $result['friendMask'];
}

function getLoc($url, $info, $subId){
    $locationReq = new LbsRequest($url);
    $locationReq->setAppId($info["appId"]);
    $locationReq->setAppPassword($info["password"]);
    $locationReq->setSubscriberId($subId);
    $locationReq->setServiceType($info["serviceType"]);
    $locationReq->setFreshness($info["freshness"]);
    $locationReq->setHorizontalAccuracy($info["hAccuracy"]);
    $locationReq->setResponseTime($info["responseTime"]);

    $lbsClient = new LbsClient();
    $lbsResponse = new LbsResponse($lbsClient->getResponse($locationReq));
    $lbsResponse->setTimeStamp(getModifiedTimeStamp($lbsResponse->getTimeStamp()));

    return $lbsResponse;
}

function updateFriend($mask, $fmask, $pdo){
    $oldFriend = getFMask($mask, $pdo);
    if($oldFriend == 'nill'){
       $sql = "UPDATE userinfo SET friendMask = :friendMask WHERE mask = :mask";
        $query = $pdo->prepare($sql);
        $query->execute(
            array(
                ':friendMask' => $fmask,
                ':mask' => $mask
            )
        );
    }else{
        $sql = "UPDATE userinfo SET lastFriendId = :lastFriendMask, friendMask = :friendMask WHERE mask = :mask";
        $query = $pdo->prepare($sql);
        $query->execute(
            array(
                ':friendMask' => $fmask,
                ':lastFriendMask' => $oldFriend,
                ':mask' => $mask
            )
        );
    }

    return "You have left succesfully the conversation.";
}

function getMatchList($mask, $pdo){
    $sql = "SELECT * FROM userinfo WHERE ( (mask != :mask) OR (lastFriendId != :mask) ) AND ( friendMask = :fmask )";
    $query = $pdo->prepare($sql);
    $query->execute(
        array(
            ':mask' => $mask,
            ':fmask' => 'nill'
        )
    );
    $result = $query->fetchAll();
    return $result;
}

function getUser($mask, $pdo){
    $sql = "SELECT * FROM userinfo WHERE mask = :mask";
    $query = $pdo->prepare($sql);
    $query->execute(
        array(
            ':mask' => $mask
        )
    );
    $result = $query->fetch();
    return $result;
}

function exitCoversation($mask, $pdo){
    $lastFriendId = getFMask($mask, $pdo);
    $sql = "UPDATE userinfo SET lastFriendId = :lastFriendId, friendMask = :friendMask WHERE mask = :mask";
    $query = $pdo->prepare($sql);
    $query->execute(
        array(
            ':lastFriendId' => $lastFriendId,
            ':friendMask' => 'nill',
            ':mask' => $mask
        )
    );
    $sql = "UPDATE userinfo SET lastFriendId = :lastFriendId, friendMask = :friendMask WHERE mask = :mask";
    $query = $pdo->prepare($sql);
    $query->execute(
        array(
            ':lastFriendId' => $mask,
            ':friendMask' => 'nill',
            ':mask' => $lastFriendId
        )
    );
    return "You have left succesfully the conversation.";
}

function signUp($mask, $info, $pdo, $loc){
    if(is_numeric($info[4]) && (strtoupper($info[3]) == 'M' || strtoupper($info[3]) == 'F')){
        $sql = "INSERT INTO userinfo (username, userId, gender, age, mask, lat, longi, lastFriendId, friendMask) VALUES (:username, :userId, :gender, :age, :mask, :lat, :longi, :lastFriendId, :friendMask)";
        $query = $pdo->prepare($sql);
        $insert = $query->execute(
            array(
                ":username" => $info[2],
                ":userId" => uniqid('fstr'),
                ":gender" => strtoupper($info[3]),
                ":age" => $info[4],
                ":mask" => $mask,
                ":lat" => $loc->getLatitude(),
                ":longi" => $loc->getLongitude(),
                ":lastFriendId" => "nill",
                ":friendMask" => "nill"
            )
        );

        if($insert > 0 ) {
            return "$info[2], you have been succesfully registered!\n Type FSTR HELP for usage commands.";
        }else{
            return "Registration unsuccessful, please try again.";
        }
    }else{
        return "Invalid Registration information. Please Try again with the format.\n FSTR SU <name> <gender M / F> <age>.";
    }
}

function sendReply($reply, $mask, $url, $info){
    $responder = new SmsSender($url);
    $responder->sms(
        $reply,
        $mask,
        $info['password'],
        $info['appId'],
        $info['srcAddr'],
        $info['deliveryStatus'],
        $info['chrgAmnt'],
        $info['encoding'],
        $info['ver'],
        $info['binaryHeader']
    );
}

function getModifiedTimeStamp($timeStamp){
    try {
        $date= new DateTime($timeStamp,new DateTimeZone('Asia/Colombo'));
    } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
    }
    return $date->format('Y-m-d H:i:s');
}

?>
