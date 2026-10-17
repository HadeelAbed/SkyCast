(function () {
    const input = document.getElementById('city');
    const clear = document.getElementById('clear-search');

    if (input && clear) {
        const sync = () => clear.classList.toggle('hidden', input.value.trim() === '');

        input.addEventListener('input', sync);
        clear.addEventListener('click', () => {
            input.value = '';
            input.focus();
            sync();
        });
        sync();
    }

    const button = document.getElementById('geo-button');
    const form = document.getElementById('geo-form');

    if (button && form) {
        const requestLocation = (auto) => {
            if (!navigator.geolocation) {
                if (!auto) {
                    window.alert(button.dataset.unsupported || 'Geolocation is not supported.');
                }
                return;
            }

            if (!auto) {
                button.disabled = true;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    document.getElementById('geo-lat').value = position.coords.latitude;
                    document.getElementById('geo-lon').value = position.coords.longitude;
                    form.submit();
                },
                () => {
                    if (!auto) {
                        button.disabled = false;
                        window.alert(button.dataset.failed || 'Could not get your location.');
                    }
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
            );
        };

        button.addEventListener('click', () => requestLocation(false));

        // First visit with no saved location: ask for the location silently.
        if (button.dataset.autoLocate === '1') {
            requestLocation(true);
        }
    }
})();
