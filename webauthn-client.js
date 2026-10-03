/* webauthn-client.js — biometrické prihlásenie (passkeys) pre appku Recepty */

function b64urlToBuffer(b64url) {
    const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
    const base64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const buf = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
    return buf.buffer;
}

function bufferToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let str = '';
    for (const b of bytes) str += String.fromCharCode(b);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Prevedie JSON od serveru (rawId, challenge... ako base64url reťazce) na skutočné binárne dáta pre navigator.credentials. */
function preparePublicKeyOptions(options, isCreation) {
    options.challenge = b64urlToBuffer(options.challenge);
    if (options.user && options.user.id) {
        options.user.id = b64urlToBuffer(options.user.id);
    }
    const listKey = isCreation ? 'excludeCredentials' : 'allowCredentials';
    if (Array.isArray(options[listKey])) {
        options[listKey] = options[listKey].map(c => ({ ...c, id: b64urlToBuffer(c.id) }));
    }
    return options;
}

/** Prevedie odpoveď z navigator.credentials na JSON, ktorý appka pošle na server. */
function credentialToJSON(credential) {
    const base = {
        id: credential.id,
        rawId: bufferToB64url(credential.rawId),
        type: credential.type,
    };
    if (credential.response.attestationObject) {
        base.response = {
            clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
            attestationObject: bufferToB64url(credential.response.attestationObject),
            // Kde je kľúč uložený (napr. „internal" = v tomto zariadení). Server si to
            // zapamätá a pri prihlásení vráti, takže prehliadač rovno vyvolá biometriu.
            transports: typeof credential.response.getTransports === 'function' ? credential.response.getTransports() : [],
        };
    } else {
        base.response = {
            clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
            authenticatorData: bufferToB64url(credential.response.authenticatorData),
            signature: bufferToB64url(credential.response.signature),
            userHandle: credential.response.userHandle ? bufferToB64url(credential.response.userHandle) : null,
        };
    }
    return base;
}

/* --------------------------------------------------------------------
   Zapamätanie, že toto zariadenie má uloženú biometriu (a pre koho).
   Ukladá sa len používateľské meno do localStorage tohto prehliadača.
   Prihlasovacia stránka vďaka tomu vie biometriu vyvolať sama a bez
   výberu účtu. Pri odhlásení sa nemaže — práve vtedy sa hodí najviac.
   -------------------------------------------------------------------- */
const PASSKEY_USER_KEY = 'recepty_passkey_user';

function getPasskeyUser() {
    try { return localStorage.getItem(PASSKEY_USER_KEY) || ''; } catch (e) { return ''; }
}

function setPasskeyUser(username) {
    try {
        if (username) localStorage.setItem(PASSKEY_USER_KEY, username);
        else localStorage.removeItem(PASSKEY_USER_KEY);
    } catch (e) { /* súkromný režim a pod. — nevadí */ }
}

/** Zaregistruje nový biometrický kľúč pre AKTUÁLNE prihláseného používateľa. */
async function registerPasskey(label) {
    const optionsRes = await fetch('webauthn/register_options.php');
    if (!optionsRes.ok) throw new Error('Nepodarilo sa pripraviť registráciu.');
    const options = preparePublicKeyOptions(await optionsRes.json(), true);

    const credential = await navigator.credentials.create({ publicKey: options });

    const verifyRes = await fetch('webauthn/register_verify.php?label=' + encodeURIComponent(label || ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(credentialToJSON(credential)),
    });
    const result = await verifyRes.json();
    if (!result.success) throw new Error(result.error || 'Registrácia zlyhala.');
    setPasskeyUser(result.username);
    return result;
}

/** Prihlási používateľa biometriou. Ak username vynecháš, ponúkne sa akýkoľvek uložený passkey. */
async function loginWithPasskey(username, remember) {
    const optionsRes = await fetch('webauthn/login_options.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: username || '' }),
    });
    if (!optionsRes.ok) {
        const err = await optionsRes.json().catch(() => ({}));
        // 404 = tento používateľ už biometriu nemá (zmazaný účet/kľúč) → prestaneme ju skúšať sami
        if (optionsRes.status === 404 && username && username === getPasskeyUser()) setPasskeyUser('');
        throw new Error(err.error || 'Nepodarilo sa pripraviť prihlásenie.');
    }
    const options = preparePublicKeyOptions(await optionsRes.json(), false);

    const credential = await navigator.credentials.get({ publicKey: options });

    const verifyRes = await fetch('webauthn/login_verify.php' + (remember ? '?remember=1' : ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(credentialToJSON(credential)),
    });
    const result = await verifyRes.json();
    if (!result.success) throw new Error(result.error || 'Prihlásenie zlyhalo.');
    setPasskeyUser(result.username);
    return result;
}
