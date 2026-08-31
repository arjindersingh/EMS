package com.ihgi.emscheckin.ui.settings

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.ihgi.emscheckin.EmsApp
import com.ihgi.emscheckin.data.ApiClient
import com.ihgi.emscheckin.data.NetworkResult
import com.ihgi.emscheckin.data.safeApiCall
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class SettingsUiState(
    val serverUrl: String = "",
    val username: String = "",
    val password: String = "",
    val isLoading: Boolean = false,
    val errorMessage: String? = null,
    val isLoggedIn: Boolean = false,
    val loggedInAsName: String = "",
)

class SettingsViewModel(application: Application) : AndroidViewModel(application) {

    private val settings = (application as EmsApp).settings

    private val _uiState = MutableStateFlow(
        SettingsUiState(
            serverUrl = settings.baseUrl,
            isLoggedIn = settings.isLoggedIn,
            loggedInAsName = settings.adminName,
        )
    )
    val uiState: StateFlow<SettingsUiState> = _uiState.asStateFlow()

    fun onServerUrlChange(value: String) = _uiState.update { it.copy(serverUrl = value, errorMessage = null) }
    fun onUsernameChange(value: String) = _uiState.update { it.copy(username = value, errorMessage = null) }
    fun onPasswordChange(value: String) = _uiState.update { it.copy(password = value, errorMessage = null) }

    fun login() {
        val state = _uiState.value
        val serverUrl = state.serverUrl.trim().trimEnd('/')
        val username = state.username.trim()
        val password = state.password

        if (serverUrl.isBlank() || username.isBlank() || password.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Server URL, username, and password are all required.") }
            return
        }
        if (!serverUrl.startsWith("http://") && !serverUrl.startsWith("https://")) {
            _uiState.update { it.copy(errorMessage = "Server URL must start with http:// or https://") }
            return
        }

        _uiState.update { it.copy(isLoading = true, errorMessage = null) }

        viewModelScope.launch {
            val api = ApiClient.create(serverUrl) { null }
            val deviceLabel = "${Build.MANUFACTURER} ${Build.MODEL}".trim()
            when (val result = safeApiCall { api.login(username, password, deviceLabel) }) {
                is NetworkResult.Success -> {
                    val body = result.data
                    if (body.success && !body.token.isNullOrBlank()) {
                        settings.baseUrl = serverUrl
                        settings.token = body.token
                        settings.adminName = body.admin?.name ?: username
                        _uiState.update {
                            it.copy(
                                isLoading = false,
                                isLoggedIn = true,
                                loggedInAsName = settings.adminName,
                                password = "",
                            )
                        }
                    } else {
                        _uiState.update { it.copy(isLoading = false, errorMessage = body.message.ifBlank { "Sign-in failed." }) }
                    }
                }
                is NetworkResult.Failure -> {
                    _uiState.update { it.copy(isLoading = false, errorMessage = result.message) }
                }
            }
        }
    }

    fun logout() {
        val token = settings.token
        val baseUrl = settings.baseUrl
        settings.clearSession()
        _uiState.update {
            it.copy(isLoggedIn = false, loggedInAsName = "", username = "", password = "")
        }

        if (!token.isNullOrBlank() && baseUrl.isNotBlank()) {
            viewModelScope.launch {
                val api = ApiClient.create(baseUrl) { token }
                safeApiCall { api.logout() }
            }
        }
    }
}
