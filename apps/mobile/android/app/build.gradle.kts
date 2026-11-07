plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release keys are supplied by the authorized builder as Gradle properties
// (e.g. ORG_GRADLE_PROJECT_EV_ANDROID_STORE_FILE). Never commit secrets.
val releaseStoreFile = providers.gradleProperty("EV_ANDROID_STORE_FILE").orNull
val releaseStorePassword = providers.gradleProperty("EV_ANDROID_STORE_PASSWORD").orNull
val releaseKeyAlias = providers.gradleProperty("EV_ANDROID_KEY_ALIAS").orNull
val releaseKeyPassword = providers.gradleProperty("EV_ANDROID_KEY_PASSWORD").orNull
val releaseSigningComplete = listOf(
    releaseStoreFile, releaseStorePassword, releaseKeyAlias, releaseKeyPassword
).all { !it.isNullOrBlank() }

// The shared EV client retains its original debug identity unless its
// authorized release owner explicitly approves an Android store package.
val approvedEvApplicationId =
    providers.gradleProperty("EV_ANDROID_APPLICATION_ID").orNull?.trim()
val defaultEvApplicationId = "com.creabiz.creabiz_ev"

android {

    signingConfigs {
        if (releaseSigningComplete) {
            create("release") {
                storeFile = file(releaseStoreFile!!)
                storePassword = releaseStorePassword!!
                keyAlias = releaseKeyAlias!!
                keyPassword = releaseKeyPassword!!
            }
        }
    }
    namespace = "com.creabiz.creabiz_ev"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }

    defaultConfig {
        applicationId = approvedEvApplicationId?.takeIf { it.isNotBlank() }
            ?: defaultEvApplicationId
        // The existing debug package identity stays unchanged when unset.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        debug {
            // Never install a debug-signed app under an explicitly approved
            // production package when release properties are present.
            if (!approvedEvApplicationId.isNullOrBlank()) {
                applicationIdSuffix = ".dev"
            }
        }
        release {
            // Never ship a release APK/AAB signed with Android debug keys.
            if (releaseSigningComplete) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
}


val verifyReleaseSigning = tasks.register("verifyReleaseSigning") {
    group = "verification"
    description = "Require a private keystore and complete release credentials."
    doLast {
        if (!releaseSigningComplete) {
            throw GradleException(
                "EV release signing requires EV_ANDROID_STORE_FILE, " +
                    "EV_ANDROID_STORE_PASSWORD, EV_ANDROID_KEY_ALIAS, " +
                    "and EV_ANDROID_KEY_PASSWORD as secure Gradle properties."
            )
        }
        if (!file(releaseStoreFile!!).isFile) {
            throw GradleException("EV release signing keystore file is missing.")
        }
    }
}

val verifyReleaseApplicationId = tasks.register("verifyReleaseApplicationId") {
    group = "verification"
    description = "Require owner-approved shared EV Android release identity."
    doLast {
        val identifier = approvedEvApplicationId
        val isApprovedFormat = identifier != null &&
            Regex("^[a-zA-Z][a-zA-Z0-9_]*(\\.[a-zA-Z][a-zA-Z0-9_]*){2,}$")
                .matches(identifier) &&
            !identifier.endsWith(".dev", ignoreCase = true) &&
            !identifier.endsWith(".test", ignoreCase = true) &&
            !identifier.endsWith(".debug", ignoreCase = true)
        if (!isApprovedFormat) {
            throw GradleException(
                "EV release requires explicitly approved EV_ANDROID_APPLICATION_ID " +
                    "(no demo, test or debug package suffix)."
            )
        }
    }
}

// Fail closed for release distribution while keeping unsigned debug/simulator
// development usable. Gradle's release tasks can be created lazily by AGP.
tasks.matching {
    it.name in setOf("assembleRelease", "bundleRelease", "packageRelease")
}.configureEach {
    dependsOn(verifyReleaseSigning, verifyReleaseApplicationId)
}

flutter {
    source = "../.."
}
