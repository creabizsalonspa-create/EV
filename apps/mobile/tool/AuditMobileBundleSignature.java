import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.CodeSigner;
import java.security.MessageDigest;
import java.security.cert.X509Certificate;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import java.util.jar.JarEntry;
import java.util.jar.JarFile;
import javax.naming.ldap.LdapName;

/** Verify an AAB upload signature, not its manifest, Play approval or device behavior. */
public class AuditMobileBundleSignature {
    private static final Set<String> REQUIRED = Set.of(
            "BundleConfig.pb", "base/manifest/AndroidManifest.xml");

    private static String hex(byte[] bytes) {
        StringBuilder value = new StringBuilder();
        for (byte b : bytes) value.append(String.format(Locale.ROOT, "%02x", b & 255));
        return value.toString();
    }

    private static String digest(Path path) throws Exception {
        MessageDigest sha = MessageDigest.getInstance("SHA-256");
        try (InputStream stream = Files.newInputStream(path)) {
            byte[] buffer = new byte[65536];
            for (int n; (n = stream.read(buffer)) != -1;) sha.update(buffer, 0, n);
        }
        return hex(sha.digest());
    }

    private static boolean signingMetadata(String name) {
        String upper = name.toUpperCase(Locale.ROOT);
        if (!upper.startsWith("META-INF/")) return false;
        String leaf = upper.substring(9);
        return !leaf.contains("/") && (leaf.equals("MANIFEST.MF") ||
                leaf.matches("[A-Z0-9_-]{1,8}\\.(SF|RSA|DSA|EC)"));
    }

    private static void require(boolean condition, String message) {
        if (!condition) throw new IllegalArgumentException(message);
    }

    public static void main(String[] args) {
        try {
            require(args.length == 2, "Usage: java AuditMobileBundleSignature.java BUNDLE.aab APPROVED_UPLOAD_SHA256");
            Path bundle = Path.of(args[0]);
            String expected = args[1].toLowerCase(Locale.ROOT);
            require(expected.matches("[0-9a-f]{64}"), "Expected approved upload certificate SHA-256");
            require(Files.isRegularFile(bundle), "Bundle is not a regular file");
            String before = digest(bundle);
            Set<String> names = new HashSet<>();
            Set<String> signed = new HashSet<>();
            try (JarFile archive = new JarFile(bundle.toFile(), true)) {
                var entries = archive.entries();
                byte[] buffer = new byte[65536];
                while (entries.hasMoreElements()) {
                    JarEntry entry = entries.nextElement();
                    String name = entry.getName();
                    require(names.add(name), "Duplicate archive entry");
                    if (entry.isDirectory() || signingMetadata(name)) continue;
                    // JarFile checks the digest only after the entire entry has been read.
                    try (InputStream stream = archive.getInputStream(entry)) {
                        while (stream.read(buffer) != -1) { /* verify full entry */ }
                    }
                    CodeSigner[] signers = entry.getCodeSigners();
                    require(signers != null && signers.length == 1, "Unsigned or multiply signed bundle entry");
                    var certificate = signers[0].getSignerCertPath().getCertificates().get(0);
                    require(certificate instanceof X509Certificate, "Unsupported signer certificate");
                    X509Certificate leaf = (X509Certificate) certificate;
                    String fingerprint = hex(MessageDigest.getInstance("SHA-256").digest(leaf.getEncoded()));
                    require(expected.equals(fingerprint), "Bundle upload signer does not match approved fingerprint");
                    for (var rdn : new LdapName(leaf.getSubjectX500Principal().getName()).getRdns()) {
                        require(!("CN".equalsIgnoreCase(rdn.getType()) &&
                                "Android Debug".equalsIgnoreCase(String.valueOf(rdn.getValue()))), "Android debug signer is not allowed");
                    }
                    signed.add(name);
                }
            }
            require(signed.containsAll(REQUIRED), "Required signed bundle entries missing");
            require(before.equals(digest(bundle)), "Bundle changed during verification");
            System.out.printf("{\"signature_verified\":true,\"signed_entries\":%d,\"sha256\":\"%s\",\"upload_signer_sha256\":\"%s\"}%n",
                    signed.size(), before, expected);
        } catch (Exception failure) {
            // Do not echo archive paths, certificate subjects, tool output or secrets.
            String reason = failure instanceof IllegalArgumentException ? failure.getMessage() : "Invalid bundle or signature verification failed";
            System.err.println("AAB SIGNATURE AUDIT FAILED: " + reason);
            System.exit(1);
        }
    }
}
