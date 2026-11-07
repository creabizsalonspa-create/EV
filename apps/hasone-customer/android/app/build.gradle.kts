plugins {
    id("com.android.application")
    id("kotlin-android")
    id("dev.flutter.flutter-gradle-plugin")
}

// Release keys are supplied by the authorized builder as Gradle properties
// (e.g. ORG_GRADLE_PROJECT_HASONE_ANDROID_STORE_FILE). Never commit secrets.
val releaseStoreFile = providers.gradleProperty("HASONE_ANDROID_STORE_FILE").orNull
val releaseStorePassword = providers.gradleProperty("HASONE_ANDROID_STORE_PASSWORD").orNull
val releaseKeyAlias = providers.gradleProperty("HASONE_ANDROID_KEY_ALIAS").orNull
val releaseKeyPassword = providers.gradleProperty("HASONE_ANDROID_KEY_PASSWORD").orNull
val releaseSigningComplete = listOf(
    releaseStoreFile, releaseStorePassword, releaseKeyAlias, releaseKeyPassword
).all { !it.isNullOrBlank() }
val mapsApiKey = providers.gradleProperty("MAPS_API_KEY").orNull
// The current .dev identity is for demos only. An authorized release builder
// must deliberately provide the approved, final store application ID.
val approvedApplicationId =
    providers.gradleProperty("HASONE_ANDROID_APPLICATION_ID").orNull?.trim()
val defaultApplicationId = "com.creabiz.hasone.customer.dev"

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
    namespace = "com.example.evcharg"
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
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = approvedApplicationId?.takeIf { it.isNotBlank() }
            ?: defaultApplicationId
        manifestPlaceholders["mapsApiKey"] = mapsApiKey ?: ""
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        debug {
            // Even if CI also supplies the approved release ID, debug-signed
            // builds must not impersonate the production package identity.
            if (!approvedApplicationId.isNullOrBlank()) {
                applicationIdSuffix = ".dev"
            }
        }
        release {
            // Never ship a release APK/AAB signed with Android debug keys.
            if (releaseSigningComplete) {
                signingConfig = signingConfigs.getByName("release")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}


val verifyReleaseSigning = tasks.register("verifyReleaseSigning") {
    group = "verification"
    description = "Require a private keystore and complete release credentials."
    doLast {
        if (!releaseSigningComplete) {
            throw GradleException(
                "HASONE release signing requires HASONE_ANDROID_STORE_FILE, " +
                    "HASONE_ANDROID_STORE_PASSWORD, HASONE_ANDROID_KEY_ALIAS, " +
                    "and HASONE_ANDROID_KEY_PASSWORD as secure Gradle properties."
            )
        }
        if (!file(releaseStoreFile!!).isFile) {
            throw GradleException("HASONE release signing keystore file is missing.")
        }
    }
}

val verifyReleaseApplicationId = tasks.register("verifyReleaseApplicationId") {
    group = "verification"
    description = "Require a deliberately approved production Android application ID."
    doLast {
        val identifier = approvedApplicationId
        val validIdentifier = identifier != null &&
            Regex("^[a-zA-Z][a-zA-Z0-9_]*(\\.[a-zA-Z][a-zA-Z0-9_]*){2,}$")
                .matches(identifier) &&
            !identifier.endsWith(".dev", ignoreCase = true)
        if (!validIdentifier) {
            throw GradleException(
                "HASONE release requires an explicitly approved " +
                    "HASONE_ANDROID_APPLICATION_ID distinct from the .dev demo ID."
            )
        }
    }
}

val verifyReleaseMapsApiKey = tasks.register("verifyReleaseMapsApiKey") {
    group = "verification"
    description = "Require an approved Google Maps Android API key for distribution."
    doLast {
        if (mapsApiKey.isNullOrBlank()) {
            throw GradleException(
                "HASONE Android release requires MAPS_API_KEY as a secured " +
                    "Gradle project property restricted to the approved app ID."
            )
        }
    }
}

// Fail closed for release distribution while keeping unsigned debug/simulator
// development usable. Gradle's release tasks can be created lazily by AGP.
tasks.matching {
    it.name in setOf("assembleRelease", "bundleRelease", "packageRelease")
}.configureEach {
    dependsOn(verifyReleaseSigning, verifyReleaseMapsApiKey, verifyReleaseApplicationId)
}

flutter {
    source = "../.."
}

dependencies {
    implementation("com.google.android.material:material:1.12.0")
}

