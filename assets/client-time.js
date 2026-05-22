function getClientLocalDateTime() {
    const now = new Date();
    return now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0') + ' ' +
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0') + ':' +
        String(now.getSeconds()).padStart(2, '0');
}

// For forms: add hidden field before submit
document.addEventListener('DOMContentLoaded', function() {
    // All forms that need client time (add data-log attribute to form)
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

    // For links (delete, reset, logout) – add client time as query param
    const links = document.querySelectorAll('a[data-log]');
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            const url = new URL(link.href, window.location.origin);
            url.searchParams.set('client_time', getClientLocalDateTime());
            link.href = url.toString();
        });
    });
});