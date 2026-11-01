// DenaGate Live: bundle the official Livewire 4 runtime through Vite.
// Alpine is included by Livewire; do not initialize another copy.
import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

// Never replay a failed payment: show a standalone recovery page on access/session errors.
Livewire.interceptRequest(({ onError }) => {
    onError(({ response, preventDefault }) => {
        if ([401, 403, 419, 422, 429, 503].includes(response.status)) {
            preventDefault();
            window.location.assign(`/?notice=${response.status}`);
        }
    });
});

Livewire.start();
