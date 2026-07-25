import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

import {
    AuthenticationController,
    RegistrationController,
    WebauthnController,
} from '@web-auth/webauthn-stimulus';

// Dedicated controllers (recommended since v5.3.0)
app.register('web-auth--webauthn-stimulus--authentication', AuthenticationController);
app.register('web-auth--webauthn-stimulus--registration', RegistrationController);

// Legacy combined controller — only register if you still rely on it
app.register('web-auth--webauthn-stimulus', WebauthnController);
