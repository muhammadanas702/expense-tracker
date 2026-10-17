<?php
require __DIR__ . '/../modules/shared/balance.php';
$fail=0; function ok($c,$m){global $fail; if(!$c){$fail++; echo "FAIL $m\n";}}
$r=shared_simplify([1=>6666,2=>-3333,3=>-3333]); ok($r==[['from'=>2,'to'=>1,'amount'=>3333],['from'=>3,'to'=>1,'amount'=>3333]],'basic');
$r=shared_simplify([1=>50,2=>-30,3=>-20]); ok(count($r)==2,'two');
$r=shared_simplify([1=>0,2=>0]); ok($r==[],'settled');
mt_srand(3);
for($i=0;$i<3000;$i++){
  $n=mt_rand(2,8); $net=[]; $tot=0;
  for($k=1;$k<$n;$k++){$v=mt_rand(-100000,100000);$net[$k]=$v;$tot+=$v;} $net[$n]=-$tot;
  $r=shared_simplify($net); $chk=$net;
  foreach($r as $p){ok($p['amount']>0 && $p['from']!=$p['to'],'pos'); $chk[$p['from']]+=$p['amount']; $chk[$p['to']]-=$p['amount'];}
  ok(array_sum(array_map('abs',$chk))===0,'settles all');
  ok(count($r)<=$n-1,'at most n-1 payments');
}
// person-to-person debts: different people never cancel each other
$r=shared_pair_debts([1=>[2=>2000],3=>[1=>500]]); // user1 owes user2 2000, user3 owes user1 500
ok($r==[['from'=>1,'to'=>2,'amount'=>2000],['from'=>3,'to'=>1,'amount'=>500]],'pairwise keeps both '.json_encode($r));
$r=shared_pair_debts([1=>[2=>1000],2=>[1=>1000]]); ok($r==[],'same pair cancels');
$r=shared_pair_debts([1=>[2=>300],2=>[1=>100]]); ok($r==[['from'=>1,'to'=>2,'amount'=>200]],'pair net');
$r=shared_pair_debts([1=>[2=>-50]]); ok($r==[['from'=>2,'to'=>1,'amount'=>50]],'overpaid flips');
echo $fail?"FAILED $fail\n":"balance tests OK\n";
