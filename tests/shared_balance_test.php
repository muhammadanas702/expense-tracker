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
echo $fail?"FAILED $fail\n":"balance tests OK\n";
