package com.ihgi.emscheckin.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Per-device server address and login token, stored via the Android Keystore.
 * Each phone provisions its own copy through the Settings screen.
 */
class SettingsRepository(context: Context) {

    private val prefs: SharedPreferences

    init {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        prefs = EncryptedSharedPreferences.create(
            context,
            "ems_checkin_settings",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    var baseUrl: String
        get() = prefs.getString(KEY_BASE_URL, "") ?: ""
        set(value) = prefs.edit().putString(KEY_BASE_URL, value.trimEnd('/')).apply()

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_TOKEN, value).apply()

    var adminName: String
        get() = prefs.getString(KEY_ADMIN_NAME, "") ?: ""
        set(value) = prefs.edit().putString(KEY_ADMIN_NAME, value).apply()

    val isLoggedIn: Boolean
        get() = baseUrl.isNotBlank() && !token.isNullOrBlank()

    fun clearSession() {
        prefs.edit().remove(KEY_TOKEN).remove(KEY_ADMIN_NAME).apply()
    }

    companion object {
        private const val KEY_BASE_URL = "base_url"
        private const val KEY_TOKEN = "token"
        private const val KEY_ADMIN_NAME = "admin_name"
    }
}
