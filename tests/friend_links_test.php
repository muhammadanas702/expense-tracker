<?php
$_SERVER['HTTP_HOST']='localhost';
require __DIR__.'/../includes/security.php'; require __DIR__.'/../config/db.php'; require __DIR__.'/../includes/assets.php';
require __DIR__.'/../modules/shared/lib.php'; require __DIR__.'/../modules/friends/lib.php';
shared_ensure_schema($conn); $fail=0; function ok($c,$m){global $fail;if(!$c){$fail++;echo "FAIL $m\n";}}
$t='fx'.bin2hex(random_bytes(3)); $u=[];
foreach(['A','B','C'] as $i=>$n){$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute([$n,"$t$i@t.local",'x']);$u[$n]=(int)$conn->lastInsertId();}
ok(!friends_request($conn,$u['A'],'bad')['ok'],'invalid email');
ok(!friends_request($conn,$u['A'],"{$t}0@t.local")['ok'],'self');
$r=friends_request($conn,$u['A'],"{$t}1@t.local"); ok($r['ok'],'request');
$g=friends_request($conn,$u['A'],"nobody$t@t.local"); ok($g['msg']===$r['msg'],'generic reply same');
ok(count(friends_list($conn,$u['A']))===0,'pending not friend');
$p=friends_pending($conn,$u['B']); ok(count($p['in'])===1,'incoming');
ok(!friends_respond($conn,$u['C'],(int)$p['in'][0]['id'],'accept'),'outsider cannot accept');
ok(!friends_respond($conn,$u['A'],(int)$p['in'][0]['id'],'accept'),'requester cannot accept');
ok(friends_respond($conn,$u['B'],(int)$p['in'][0]['id'],'accept'),'accept');
$l=friends_list($conn,$u['A']); ok(isset($l[$u['B']])&&$l[$u['B']]['direct'],'A sees B'); ok(isset(friends_list($conn,$u['B'])[$u['A']]),'B sees A');
ok(friends_get($conn,$u['A'],$u['B'])!==null,'get zero-group friend');
ok(friends_request($conn,$u['B'],"{$t}0@t.local")['msg']==='You are already friends.','already');
$lid=$l[$u['B']]['link_id']; ok(friends_respond($conn,$u['A'],$lid,'remove'),'remove'); ok(!friends_list($conn,$u['A']),'removed');
friends_request($conn,$u['A'],"{$t}2@t.local"); friends_request($conn,$u['C'],"{$t}0@t.local"); ok(isset(friends_list($conn,$u['A'])[$u['C']]),'cross request auto-accept');
echo $fail?"$fail FAILED\n":"friend link tests OK\n";
