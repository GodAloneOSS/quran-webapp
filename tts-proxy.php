<?php
error_reporting(0);
$text=isset($_GET['q'])?$_GET['q']:'';
$lang=isset($_GET['tl'])?$_GET['tl']:'ta';
if(empty($text)){http_response_code(400);die('Missing text');}
if(strlen($text)>1000){$text=substr($text,0,1000);}
$lang=preg_replace('/[^a-zA-Z-]/','',$lang);
$e=urlencode($text);
$url="https://translate.googleapis.com/translate_tts?ie=UTF-8&tl={$lang}&client=gtx&q={$e}";
$ctx=stream_context_create(array('http'=>array('method'=>'GET','header'=>"User-Agent: Mozilla/5.0\r\nReferer: https://translate.google.com/\r\n",'timeout'=>10),'ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false)));
$d=@file_get_contents($url,false,$ctx);
if($d&&strlen($d)>100){header('Content-Type: audio/mpeg');header('Content-Length: '.strlen($d));header('Cache-Control: public, max-age=86400');echo $d;}else{http_response_code(502);echo 'TTS unavailable';}
