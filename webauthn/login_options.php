<?php
require_once __DIR__ . '/webauthn_common.php';

header('Content-Type: application/json; charset=utf-8');

use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialDescriptor;

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim((string) ($input['username'] ?? ''));

    $allowCredentials = [];
    if ($username !== '') {
        $stmt = $pdo->prepare(
            'SELECT wc.credential_id, wc.credential_record FROM webauthn_credentials wc
             JOIN users u ON u.id = wc.user_id WHERE u.username = ?'
        );
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            http_response_code(404);
            echo json_encode(['error' => 'Pre tohto používateľa nie je zaregistrovaná biometria.']);
            exit;
        }
        foreach ($rows as $row) {
            // Kde je kľúč uložený („internal" = priamo v tomto zariadení) si prehliadač
            // povedal pri registrácii. Keď mu to vrátime, nepýta sa „aké zariadenie
            // chceš použiť" a rovno vyvolá odtlačok / Face ID.
            $record = json_decode((string) ($row['credential_record'] ?? ''), true);
            $transports = (is_array($record) && is_array($record['transports'] ?? null))
                ? array_values(array_filter($record['transports'], 'is_string'))
                : [];
            $allowCredentials[] = PublicKeyCredentialDescriptor::create('public-key', b64url_decode($row['credential_id']), $transports);
        }
    }
    // Ak username nie je zadané, ide o "usernameless" prihlásenie — prehliadač
    // ponúkne akýkoľvek passkey uložený pre túto doménu.

    $requestOptions = PublicKeyCredentialRequestOptions::create(
        random_bytes(32),
        rpId: WEBAUTHN_RP_ID,
        allowCredentials: $allowCredentials,
        userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
    );

    $_SESSION['webauthn_login_options'] = webauthn_serializer()->serialize($requestOptions, 'json');

    echo webauthn_serializer()->serialize($requestOptions, 'json', [
        \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
    ]);
} catch (Throwable $e) {
    debug_log('WEBAUTHN LOGIN OPTIONS ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Nepodarilo sa pripraviť prihlásenie biometriou.']);
}
