function getClientLocalDateTime() {
    const now = new Date();
    return now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0') + ' ' +
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0') + ':' +
        String(now.getSeconds()).padStart(2, '0');
}

document.addEventListener('DOMContentLoaded', function() {
    // Forms marked with data-log get the browser's local time as a hidden field.
    const forms = document.querySelectorAll('form[data-log]');
    forms.forEach(form => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'client_local_time';
        form.appendChild(input);
        form.addEventListener('submit', function() {
            input.value = getClientLocalDateTime();
        });
    });

    // Links that change data (logout, delete, reset) must be sent as POST with a CSRF token,
    // never as a plain GET link that any other web page could trigger.
    //   <a href="delete.php" data-post data-csrf="TOKEN" data-fields='{"id":5}'>Delete</a>
    // An onclick="return confirm(...)" on the link still runs first and can cancel the action.
    const postLinks = document.querySelectorAll('a[data-post]');
    postLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (e.defaultPrevented) {
                return;
            }
            e.preventDefault();

            const url = new URL(link.getAttribute('href'), window.location.href);
            url.search = '';

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url.toString();

            let fields = {};
            try {
                fields = JSON.parse(link.getAttribute('data-fields') || '{}') || {};
            } catch (err) {
                fields = {};
            }
            fields.csrf_token = link.getAttribute('data-csrf') || '';
            fields.client_local_time = getClientLocalDateTime();

            Object.keys(fields).forEach(name => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = String(fields[name]);
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
        });
    });
});
