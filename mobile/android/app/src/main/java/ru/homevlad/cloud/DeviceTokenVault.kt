package ru.homevlad.cloud

import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.suspendCancellableCoroutine
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

internal class DeviceTokenVault(private val activity: FragmentActivity) {
    private val prefs = activity.getSharedPreferences("device-login-" + BuildConfig.HOST, Context.MODE_PRIVATE)
    private val alias = "homevlad-device-login-" + BuildConfig.HOST
    private val authenticators = BiometricManager.Authenticators.BIOMETRIC_STRONG
    private val store get() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    val hasToken get() = prefs.contains("ciphertext") && prefs.contains("iv")
    val available get() = BiometricManager.from(activity).canAuthenticate(authenticators) == BiometricManager.BIOMETRIC_SUCCESS

    fun forget() {
        store.deleteEntry(alias)
        check(prefs.edit().clear().commit()) { "Не удалось удалить ключ входа" }
    }

    private fun newKey(): SecretKey {
        val spec = KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true)
        if (Build.VERSION.SDK_INT >= 30) {
            spec.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
        } else {
            @Suppress("DEPRECATION")
            spec.setUserAuthenticationValidityDurationSeconds(-1)
        }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(spec.build())
        }.generateKey()
    }

    suspend fun save(token: String) {
        forget()
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, newKey()) }
        val authenticated = authenticate(cipher, "Включить вход по отпечатку")
        val plaintext = token.toByteArray(Charsets.UTF_8)
        try {
            val encrypted = authenticated.doFinal(plaintext)
            check(prefs.edit().putString("ciphertext", Base64.encodeToString(encrypted, Base64.NO_WRAP))
                .putString("iv", Base64.encodeToString(authenticated.iv, Base64.NO_WRAP)).commit()) {
                "Не удалось сохранить ключ входа"
            }
        } finally { plaintext.fill(0) }
    }

    suspend fun read(): String {
        val key = store.getKey(alias, null) as? SecretKey ?: error("Войдите с паролем и заново включите биометрию")
        val iv = Base64.decode(prefs.getString("iv", null), Base64.NO_WRAP)
        val encrypted = Base64.decode(prefs.getString("ciphertext", null), Base64.NO_WRAP)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply {
            init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, iv))
        }
        val plaintext = authenticate(cipher, "Войти по отпечатку").doFinal(encrypted)
        return try { plaintext.toString(Charsets.UTF_8) } finally { plaintext.fill(0) }
    }

    private suspend fun authenticate(cipher: Cipher, title: String): Cipher =
        suspendCancellableCoroutine { continuation ->
            val prompt = BiometricPrompt(activity, ContextCompat.getMainExecutor(activity),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                        if (!continuation.isActive) return
                        val unlocked = result.cryptoObject?.cipher
                        if (unlocked == null) continuation.resumeWithException(IllegalStateException("Ключ недоступен"))
                        else continuation.resume(unlocked)
                    }

                    override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                        if (!continuation.isActive) return
                        val cancelled = errorCode in setOf(BiometricPrompt.ERROR_NEGATIVE_BUTTON,
                            BiometricPrompt.ERROR_USER_CANCELED, BiometricPrompt.ERROR_CANCELED)
                        continuation.resumeWithException(if (cancelled) CancellationException() else IllegalStateException(errString.toString()))
                    }
                })
            continuation.invokeOnCancellation { activity.runOnUiThread { prompt.cancelAuthentication() } }
            prompt.authenticate(BiometricPrompt.PromptInfo.Builder().setTitle(title)
                .setAllowedAuthenticators(authenticators).setNegativeButtonText("Отмена").build(),
                BiometricPrompt.CryptoObject(cipher))
        }
}
