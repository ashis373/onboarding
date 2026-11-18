<?php
$data = 'SFR4ODIzdS96L3l1dFVEdkdyZHpuKzMwOVBXUjZQSFkwWUd2N29FRHpTQkJ1eVVnbnhDWXJ1K3JtSUY0MEFQNHhQamdjU2VwanJEazdwcng1LzdKMk5lNTYrNWkxYmFjdHZoVktGTTk5RHNMaUJBNURQbHhnblpEL1J3OVRnVE05TmQxVXZIZEIybXBsWmYvWkhxRThzc0p6QS9PV0RnWURZN3N0QUNEaHhiNm1LV0xYL3RDYlV5TmFSM1dEVDE0V2RsR3RYWkt4bzh6WUIvdEl6WkhxQT09OjqnO+8gi+ILWtt77s6hyjLJ';
$sig = '0f4c6ccf29c336c5359b0ee9ed918c2e695265cba0285d54b8fcff89cddba916';
$secret = '6724164445813902f62a0b22f9545058679c38ff352ea5e3a66e483a334aae22';

$calcSig = hash_hmac('sha256', $data, $secret);
echo "Sig Matches Secret? " . ($calcSig === $sig ? "YES" : "NO") . "\n";
echo "Calculated Sig: $calcSig\n";
echo "Provided Sig:   $sig\n";

$decoded = base64_decode($data, true);
echo "Base64 Decodes? " . ($decoded !== false ? "YES" : "NO") . "\n";
$parts = explode('::', $decoded, 2);
echo "Has '::'? " . (count($parts) === 2 ? "YES" : "NO") . "\n";
if (count($parts) === 2) {
    $key = hash('sha256', $secret, true);
    $decrypted = openssl_decrypt($parts[0], 'aes-256-cbc', $key, 0, $parts[1]);
    echo "Decrypted: " . $decrypted . "\n";
}
