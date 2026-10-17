<?php
require __DIR__ . '/../modules/shared/split.php'';
$fail=0; function ok($c,$m){global $fail; if(!$c){$fail++; echo "FAIL $m\n";}}
// equal: 100.00 / 3
$r=shared_compute_split(10000,'equal',[3,1,2],[]); ok(array_sum($r['shares'])===10000,'eq sum'); ok($r['shares']===[1=>3334,2=>3333,3=>3333],'eq dist '.json_encode($r['shares']));
// random fuzz
mt_srand(7);
for($i=0;$i<3000;$i++){
  $t=mt_rand(1,99999999); $n=mt_rand(1,9); $ids=range(10,9+$n);
  $r=shared_compute_split($t,'equal',$ids,[]); ok(array_sum($r['shares'])===$t,'fz eq');
  $in=[];$tot=0;foreach($ids as $u){$p=mt_rand(0,50);$in[$u]=(string)$p;$tot+=$p;}
  if($tot>0){$r=shared_compute_split($t,'shares',$ids,$in); ok(is_array($r)&&array_sum($r['shares'])===$t,'fz shares');}
  // percent: random bp summing 10000
  $cuts=[];for($k=0;$k<$n-1;$k++)$cuts[]=mt_rand(0,10000);sort($cuts);$cuts=array_merge([0],$cuts,[10000]);$in=[];
  foreach($ids as $k=>$u){$in[$u]=shared_from_minor($cuts[$k+1]-$cuts[$k]);}
  $r=shared_compute_split($t,'percent',$ids,$in); ok(is_array($r)&&array_sum($r['shares'])===$t,'fz pct '.json_encode($r));
}
ok(is_string(shared_compute_split(1000,'exact',[1,2],[1=>'4',2=>'5'])),'exact mismatch');
$r=shared_compute_split(1000,'exact',[1,2],[1=>'4',2=>'6']); ok($r['shares']===[1=>400,2=>600],'exact ok');
ok(is_string(shared_compute_split(1000,'percent',[1,2],[1=>'33.33',2=>'66.66'])),'pct 99.99');
ok(is_string(shared_compute_split(1000,'percent',[1,2],[1=>'-1',2=>'101'])),'neg');
ok(is_string(shared_compute_split(1000,'exact',[1],[1=>'1e3'])),'sci');
ok(is_string(shared_compute_split(1000,'shares',[1,2],[1=>'0',2=>'0'])),'zero shares');
ok(shared_to_minor('0.1')===10 && shared_to_minor('1.005')===null && shared_to_minor('.5')===null,'minor parse');
echo $fail? "FAILED $fail\n":"split tests OK\n";
