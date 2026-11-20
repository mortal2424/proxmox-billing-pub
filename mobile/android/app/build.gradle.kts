plugins { id("com.android.application"); id("org.jetbrains.kotlin.android"); id("org.jetbrains.kotlin.plugin.compose") }
android {
    namespace = "ru.homevlad.cloud"
    compileSdk = 35
    defaultConfig {
        applicationId = "ru.homevlad.cloud"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
        buildConfigField("String", "HOST", "\"homevlad.ru\"")
    }
    buildTypes {
        debug {
            applicationIdSuffix = ".dev"
            resValue("string", "app_name", "HomeVlad")
            buildConfigField("String", "HOST", "\"dev.homevlad.ru\"")
        }
        release {
            isMinifyEnabled = false
            resValue("string", "app_name", "HomeVlad")
        }
    }
    buildFeatures { compose = true; buildConfig = true }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
}
dependencies {
    implementation(platform("androidx.compose:compose-bom:2024.12.01"))
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
