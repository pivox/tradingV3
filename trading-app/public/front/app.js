(function () {
    function valueAtPath(source, path) {
        return path.split('.').reduce(function (value, key) {
            if (value === null || value === undefined) {
                return undefined;
            }

            return value[key];
        }, source);
    }

    function updateFields(container, payload) {
        container.querySelectorAll('[data-json-field]').forEach(function (node) {
            var value = valueAtPath(payload, node.getAttribute('data-json-field') || '');
            if (value === undefined || value === null) {
                return;
            }

            node.textContent = String(value);
        });
    }

    document.querySelectorAll('[data-auto-refresh]').forEach(function (container) {
        var url = container.getAttribute('data-refresh-url');
        var interval = Number(container.getAttribute('data-refresh-interval') || '30000');
        if (!url || interval < 5000) {
            return;
        }

        window.setInterval(function () {
            fetch(url, {headers: {'Accept': 'application/json'}})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('refresh failed');
                    }

                    return response.json();
                })
                .then(function (payload) {
                    updateFields(document, payload);
                })
                .catch(function () {
                    container.classList.add('refresh-error');
                });
        }, interval);
    });
}());
