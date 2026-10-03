<?php
require_once __DIR__ . '/webauthn_common.php';

header('Content-Type: application/json; charset=utf-8');

use Webauthn\PublicKeyCredential;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\CredentialRecord;

try {
    if (empty($_SESSION['webauthn_login_options'])) {
        throw new RuntimeException('Prihlasovacia session vypršala, skús to znova.');
    }

    $requestOptions = webauthn_serializer()->deserialize(
        $_SESSION['webauthn_login_options'],
        PublicKeyCredentialRequestOptions::class,
        'json'
    );

    $raw = file_get_contents('php://input');
    $publicKeyCredential = webauthn_serializer()->deserialize($raw, PublicKeyCredential::class, 'json');

    if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
        throw new RuntimeException('Neplatný typ odpovede z prehliadača.');
    }

    $credentialIdB64 = b64url_encode($publicKeyCredential->rawId);

    $stmt = $pdo->prepare(
        'SELECT wc.id, wc.credential_record, u.id AS user_id, u.username, u.role, u.full_name
         FROM webauthn_credentials wc JOIN users u ON u.id = wc.user_id
         WHERE wc.credential_id = ?'
    );
    $stmt->execute([$credentialIdB64]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Tento kľúč nie je zaregistrovaný.');
    }

    $credentialRecord = webauthn_serializer()->deserialize($row['credential_record'], CredentialRecord::class, 'json');

    $csmFactory = new CeremonyStepManagerFactory();
    $validator = AuthenticatorAssertionResponseValidator::create($csmFactory->requestCeremony());

    $updatedRecord = $validator->check(
        $credentialRecord,
        $publicKeyCredential->response,
        $requestOptions,
        WEBAUTHN_ORIGIN,
        null
    );

    // Nový counter — ochrana proti klonovaniu autentikátora.
    $updatedJson = webauthn_serializer()->serialize($updatedRecord, 'json');
    $upd = $pdo->prepare('UPDATE webauthn_credentials SET credential_record = ?, last_used_at = NOW() WHERE id = ?');
    $upd->execute([$updatedJson, $row['id']]);

    unset($_SESSION['webauthn_login_options']);

    start_authenticated_session([
        'id'        => $row['user_id'],
        'username'  => $row['username'],
        'role'      => $row['role'],
        'full_name' => $row['full_name'],
    ]);

    // „Zostať prihlásený" zaškrtnuté na prihlasovacej stránke
    if (!empty($_GET['remember'])) {
        remember_create($pdo, (int) $row['user_id']);
    }

    echo json_encode(['success' => true, 'username' => $row['username']]);
} catch (Throwable $e) {
    debug_log('WEBAUTHN LOGIN VERIFY ERROR: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Prihlásenie biometriou zlyhalo: ' . $e->getMessage()]);
}
