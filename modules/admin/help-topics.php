<?php
/** Plain-words problems, each pointing at the admin pages that fix it. Used by "Where do I go?" and by the "When do I need this page?" box on every admin page. */
function admin_help_topics(): array
{
    return [
        'People cannot get in' => [
            ['People say the login code never arrives', 'Usually the e-mail service has stopped working.', [['mail', 'See if the e-mails were sent or failed, and why.'], ['feat', 'Switch "E-mailed login code" off so people can log in with just their password until e-mail works again.']]],
            ['A new person cannot create an account', 'The confirmation e-mail is not arriving, or sign-ups are closed.', [['mail', 'Check the confirmation e-mail was sent.'], ['feat', 'Check "New sign-ups" is on.'], ['set', 'Check sign-ups are open in Settings.']]],
            ['One user cannot sign in, the others can', 'Their account may be blocked, or they forgot the password.', [['users', 'Find them, unblock them or send a password reset.'], ['ulog', 'See their recent sign-in attempts.']]],
            ['Someone is trying to break into accounts', 'Many failed logins or strange visitors.', [['sec', 'See blocked visitors and sign-in attempts.'], ['users', 'Block the account if needed.']]],
        ],
        'The website itself has a problem' => [
            ['The website shows "Error 500", a blank page or is down', 'Something broke on the server, or the database is not answering.', [['hlth', 'Run the health check: it shows which part is unhealthy and the latest error.'], ['bak', 'Make sure a recent backup exists.'], ['set', 'Pause the site for a while in Settings (maintenance) while you fix it.']]],
            ['The website is slow', 'The server or database is busy, or a page is heavy.', [['spd', 'Measure which pages are slow.'], ['hlth', 'Check disk space and old files piling up.']]],
            ['I need to make changes and do not want users to see a half-finished site', 'Use maintenance mode.', [['set', 'Switch maintenance on, do your work, switch it off.'], ['bak', 'Make a backup first.']]],
            ['I deleted something by mistake or data looks wrong', 'Restore from a backup.', [['bak', 'Download or restore a backup.'], ['log', 'See who changed what, and when.']]],
        ],
        'One part of the app is broken' => [
            ['Budgets, Goals, Groups, Google sign-in or another option misbehaves', 'Switch just that option off. Everyone else keeps working and no data is lost.', [['feat', 'Find the option, press its switch. Press again when it is fixed.']]],
            ['E-mails (login codes, welcome mails) are not arriving at all', 'The e-mail settings may be wrong or the e-mail provider has a problem.', [['mail', 'See each failed e-mail and the reason.'], ['hlth', 'The health page also tests the e-mail connection.']]],
        ],
        'Looks and wording' => [
            ['Change the name, colours, logo or pictures of the website', 'All in one place.', [['brand', 'Branding: name, colours, logo, pictures, and texts you click to edit.']]],
            ['Fix a spelling mistake or change a sentence on the home page', 'You can click the text itself and type.', [['brand', 'Branding → Edit texts → Start editing texts.']]],
            ['Add or remove an expense category or a currency', 'These are lists people pick from.', [['cat', 'Categories.'], ['cur', 'Currencies and rates.']]],
        ],
        'Users and your team' => [
            ['A user wrote to us for help', 'Their message is waiting in Support.', [['sup', 'Read it and reply.']]],
            ['I want to tell all users something', 'Show a notice bar at the top of the app.', [['ann', 'Write an announcement.']]],
            ['Give a team member access, or take it away', 'Roles decide what each person can open.', [['users', 'Change the person\'s role.'], ['roles', 'Decide what each role can open (owner only).']]],
            ['A shared group is being misused', 'For example spam or offensive names.', [['grp', 'Look inside the group and remove it.']]],
            ['Who changed this setting?', 'Every admin action is recorded.', [['log', 'Audit log: who, what and when.']]],
            ['How many people use the app?', 'Numbers and charts.', [['ov', 'Dashboard.']]],
        ],
    ];
}
