<?php
require_once __DIR__ . '/../includes/public-layout.php';
ef_public_start([
    'title' => 'Money guides: budgeting, tracking and splitting bills – ExpenseFlow',
    'desc' => 'Practical guides on tracking monthly expenses, the 50/30/20 budget rule and splitting bills with friends.',
    'path' => 'guides/', 'h1' => 'Money guides',
]);
?>
<p class="lead">Short, practical guides for tracking money and sharing costs.</p>
<div class="gc">
  <a class="g" href="track-monthly-expenses.php"><b>How to track your monthly expenses</b><span>A 10 minutes a week routine with categories that work.</span></a>
  <a class="g" href="budget-50-30-20-rule.php"><b>The 50/30/20 budget rule</b><span>Needs, wants and savings with a simple example.</span></a>
  <a class="g" href="split-expenses-with-friends.php"><b>Split expenses with friends fairly</b><span>Trips, rent and dinners without awkward maths.</span></a>
</div>
<?php ef_public_end();
