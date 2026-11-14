<?php

namespace Tests\Support;

trait CreatesChargerCertificateChain
{
    private function certificateChain(string $identity = 'ev-test-chain', string $kind = 'valid'): array
    {
        $directory = sys_get_temp_dir().'/charger-chain-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $pathLength = $kind === 'path_length' ? 0 : 2;
        $basic = $kind === 'non_ca' ? 'CA:FALSE' : ($kind === 'two_intermediates' ? 'CA:TRUE,pathlen:1' : 'CA:TRUE,pathlen:0');
        $usage = $kind === 'non_ca' ? 'digitalSignature' : 'keyCertSign,cRLSign';
        $extra = $kind === 'issuer_purpose' ? "extendedKeyUsage=serverAuth\n" : '';
        $configuration = "[req]\ndistinguished_name=dn\n[dn]\n[root]\nbasicConstraints=critical,CA:TRUE,pathlen:{$pathLength}\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[issuer]\nbasicConstraints=critical,{$basic}\nkeyUsage=critical,{$usage}\nsubjectKeyIdentifier=hash\n{$extra}[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n[issuer_leaf]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n";
        $config = $directory.'/openssl.cnf';
        file_put_contents($config, $configuration);
        $options = ['config' => $config, 'digest_alg' => 'sha256'];
        $rootKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rootCsr = openssl_csr_new(['commonName' => 'Synthetic pinned root'], $rootKey, $options);
        $root = openssl_csr_sign($rootCsr, null, $rootKey, 10, $options + ['x509_extensions' => 'root'], random_int(1, 1000000));
        openssl_x509_export($root, $rootPem);
        $issuerKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $issuerCsr = openssl_csr_new(['commonName' => 'Synthetic model issuer'], $issuerKey, $options);
        $issuer = openssl_csr_sign($issuerCsr, $root, $rootKey, 7, $options + ['x509_extensions' => 'issuer'], random_int(1, 1000000));
        openssl_x509_export($issuer, $issuerPem);
        $chainPem = $issuerPem;
        if ($kind === 'two_intermediates') {
            $nextKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $nextCsr = openssl_csr_new(['commonName' => 'Synthetic charging unit issuer'], $nextKey, $options);
            $next = openssl_csr_sign($nextCsr, $issuer, $issuerKey, 5, $options + ['x509_extensions' => 'issuer_leaf'], random_int(1, 1000000));
            openssl_x509_export($next, $nextPem);
            $chainPem = $issuerPem."\n".$nextPem; // Deliberately reverse the supplied path order.
            $issuer = $next;
            $issuerKey = $nextKey;
        }
        $leafKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $leafCsr = openssl_csr_new(['commonName' => $identity], $leafKey, $options);
        $leaf = openssl_csr_sign($leafCsr, $issuer, $issuerKey, 3, $options + ['x509_extensions' => 'leaf'], random_int(1, 1000000));
        openssl_x509_export($leaf, $leafPem);
        $bundle = $directory.'/root.pem';
        file_put_contents($bundle, $rootPem);
        chmod($bundle, 0600);
        config(['csms.certificate_management_enabled' => true, 'csms.certificate_issuer_bundle' => $bundle, 'csms.certificate_issuer_pins' => [openssl_x509_fingerprint($rootPem, 'sha256')], 'csms.certificate_denied_issuers' => []]);
        $this->beforeApplicationDestroyed(function () use ($directory) {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        });

        return [$leafPem, $chainPem, $rootPem];
    }
}
