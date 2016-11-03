#!/usr/bin/env php
<?php

require_once('./websockets.php');

class jsonChatServer extends WebSocketServer {
  protected $maxBufferSize = 1048576; //1MB... overkill for an echo server, but potentially plausible for other applications.
  public $userNames = array();

  protected function process ($user, $message) {
     try{
       $data = json_decode($message);
       $data->userID = $user->id;
       $data->date = time();
       switch($data->type){
         case 'userID':
           $this->send($user, json_encode($data));
           break;
         case 'userName':
           if(in_array($data->data, $this->userNames)){
             $userName = $data->data;
             $pos = strrpos($userName,'-');
             if($pos!==false){
               $userBase = substr($userName,0,$pos);
               $userIndex = intval(substr($userName,$pos+1));
             }
             else{
               $userBase = $userName;
               $userIndex = 0;
             }

             do{
               $userIndex++;
               $new_userName = "$userBase-$userIndex";
             }while(in_array($new_userName,$this->userNames));
             $userName = $new_userName;

             $data->data = $userName;
             $data->type = 'rejectUserName';
           }
           $this->stdout("User [$user->id] = $data->data");
           $this->userNames[$user->id] = $data->data;
           $this->send($user, json_encode($data));

           //Send user change to rest of users
           $data->type='userName';
           $json_msg = json_encode($data);
           foreach($this->users as $u){
             if($u!=$user) $this->send($u, $json_msg);
           }
           break;
         case 'userList':
           $usersList = array();
           foreach($this->userNames as $k=>$v){
             $u = new stdClass();
             $u->id = $k;
             $u->name = $v;
             $usersList[] = $u;
           }
           $data->data=$usersList;
           $this->send($user, json_encode($data));
           break;
         case 'message':
           if($data->data->to=='-1'){
             foreach($this->users as $u){
               $this->send($u,$message);
             }
           }
           else{
              $this->send($data->data->to,$message);
           }
           break;
       }
     }catch(Exception $e){
       $this->stdout($e->getMessage());
     }
  }
  
  protected function connected ($user) {
    // Do nothing: This is just an echo server, there's no need to track the user.
    // However, if we did care about the users, we would probably have a cookie to
    // parse at this step, would be looking them up in permanent storage, etc.

    //Send user id
    $message = new stdClass();
    $message->type='userID';
    $message->data=$user->id;
    $this->send($user, json_encode($message));

    $message->type='userOpen';
    $json_msg = json_encode($message);
    foreach($this->users as $u){
      if($u!=$user) $this->send($u, $json_msg);
    }
  }
  
  protected function closed ($user) {
    // Do nothing: This is where cleanup would go, in case the user had any sort of
    // open files or other objects associated with them.  This runs after the socket 
    // has been closed, so there is no need to clean up the socket itself here.

    //Send user id disconnected to users
    if(isset($this->userNames[$user->id])){
      $message = new stdClass();
      $message->type='userClosed';
      $message->data = new stdClass();
      $message->data->userID = $user->id;
      $message->data->userName = $this->userNames[$user->id];
      $json_msg = json_encode($message);
      foreach($this->users as $u){
        if($u!=$user) $this->send($u, $json_msg);
      }
      unset($this->userNames[$user->id]);
    }
  }
}

$server = new jsonChatServer("127.0.0.1","9000");

try {
  $server->run();
}
catch (Exception $e) {
  $server->stdout($e->getMessage());
}