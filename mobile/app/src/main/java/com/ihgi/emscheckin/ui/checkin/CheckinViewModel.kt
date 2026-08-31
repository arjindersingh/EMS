package com.ihgi.emscheckin.ui.checkin

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.ihgi.emscheckin.EmsApp
import com.ihgi.emscheckin.data.ApiClient
import com.ihgi.emscheckin.data.NetworkResult
import com.ihgi.emscheckin.data.dto.CandidateDto
import com.ihgi.emscheckin.data.dto.EventDto
import com.ihgi.emscheckin.data.dto.RecentAttendanceEntry
import com.ihgi.emscheckin.data.dto.RegistrationDto
import com.ihgi.emscheckin.data.safeApiCall
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class CheckinTab { QR, MANUAL, CODE }

data class StatusMessage(val text: String, val isError: Boolean)

data class CheckinUiState(
    val events: List<EventDto> = emptyList(),
    val selectedEventId: Int = 0,
    val activeTab: CheckinTab = CheckinTab.QR,
    val status: StatusMessage? = null,
    val announcement: String? = null,
    val registrations: List<RegistrationDto> = emptyList(),
    val manualSearch: String = "",
    val codeInput: String = "",
    val codeCandidate: CandidateDto? = null,
    val recent: List<RecentAttendanceEntry> = emptyList(),
    val isBusy: Boolean = false,
    val sessionExpired: Boolean = false,
) {
    val filteredRegistrations: List<RegistrationDto>
        get() {
            val term = manualSearch.trim().lowercase()
            if (term.isEmpty()) return registrations
            return registrations.filter { reg ->
                listOf(reg.name, reg.designation, reg.institution_name, reg.mobile, reg.official_email)
                    .any { it?.lowercase()?.contains(term) == true }
            }
        }
}

class CheckinViewModel(application: Application) : AndroidViewModel(application) {

    private val settings = (application as EmsApp).settings
    private val api by lazy { ApiClient.forSettings(settings) }

    private val _uiState = MutableStateFlow(CheckinUiState())
    val uiState: StateFlow<CheckinUiState> = _uiState.asStateFlow()

    private var lastScanAt = 0L

    init {
        loadEvents()
    }

    fun selectTab(tab: CheckinTab) = _uiState.update { it.copy(activeTab = tab) }

    fun loadEvents() {
        viewModelScope.launch {
            when (val result = safeApiCall { api.getEvents() }) {
                is NetworkResult.Success -> {
                    val events = result.data.events
                    _uiState.update {
                        val selected = if (it.selectedEventId != 0) it.selectedEventId else events.firstOrNull()?.event_id ?: 0
                        it.copy(events = events, selectedEventId = selected)
                    }
                    if (events.isNotEmpty()) {
                        loadRegistrations()
                        loadRecent()
                    }
                }
                is NetworkResult.Failure -> handleFailure(result)
            }
        }
    }

    fun selectEvent(eventId: Int) {
        _uiState.update { it.copy(selectedEventId = eventId, registrations = emptyList(), recent = emptyList(), codeCandidate = null) }
        loadRegistrations()
        loadRecent()
    }

    fun onManualSearchChange(value: String) = _uiState.update { it.copy(manualSearch = value) }
    fun onCodeInputChange(value: String) = _uiState.update { it.copy(codeInput = value.filter(Char::isDigit).take(5)) }

    fun loadRegistrations() {
        val eventId = _uiState.value.selectedEventId
        if (eventId <= 0) return
        viewModelScope.launch {
            when (val result = safeApiCall { api.getRegistrations(eventId) }) {
                is NetworkResult.Success -> _uiState.update { it.copy(registrations = result.data.registrations) }
                is NetworkResult.Failure -> handleFailure(result)
            }
        }
    }

    fun loadRecent() {
        val eventId = _uiState.value.selectedEventId
        if (eventId <= 0) return
        viewModelScope.launch {
            val fields = mapOf("action" to "recent", "event_id" to eventId.toString())
            when (val result = safeApiCall { api.recentAttendance(fields) }) {
                is NetworkResult.Success -> _uiState.update { it.copy(recent = result.data.recent_attendance) }
                is NetworkResult.Failure -> Unit // Non-critical; leave the existing list as-is.
            }
        }
    }

    /** Called by the QR analyzer for every decoded frame; debounces repeats like the web page's 1.5s window. */
    fun onQrScanned(payload: String) {
        val now = System.currentTimeMillis()
        if (now - lastScanAt < 1500) return
        lastScanAt = now
        val eventId = _uiState.value.selectedEventId
        if (eventId <= 0) return

        submitCheckin(
            fields = mapOf("action" to "mark_qr", "event_id" to eventId.toString(), "qr_payload" to payload),
        )
    }

    fun checkInManually(registrationId: Int) {
        val eventId = _uiState.value.selectedEventId
        if (eventId <= 0) return
        submitCheckin(
            fields = mapOf("action" to "mark_manual", "event_id" to eventId.toString(), "registration_id" to registrationId.toString()),
        )
    }

    fun lookupCode() {
        val eventId = _uiState.value.selectedEventId
        val code = _uiState.value.codeInput
        if (eventId <= 0 || code.length != 5) return

        _uiState.update { it.copy(isBusy = true) }
        viewModelScope.launch {
            val fields = mapOf("action" to "lookup_checkin_code", "event_id" to eventId.toString(), "checkin_code" to code)
            when (val result = safeApiCall { api.checkinAction(fields) }) {
                is NetworkResult.Success -> {
                    val body = result.data
                    if (body.success && body.candidate != null) {
                        _uiState.update { it.copy(isBusy = false, codeCandidate = body.candidate, status = StatusMessage(body.message, false)) }
                    } else {
                        _uiState.update { it.copy(isBusy = false, codeCandidate = null, status = StatusMessage(body.message, true)) }
                    }
                }
                is NetworkResult.Failure -> {
                    _uiState.update { it.copy(isBusy = false) }
                    handleFailure(result)
                }
            }
        }
    }

    fun confirmCodeCheckin() {
        val eventId = _uiState.value.selectedEventId
        val registrationId = _uiState.value.codeCandidate?.registration_id ?: return
        submitCheckin(
            fields = mapOf("action" to "mark_checkin_code", "event_id" to eventId.toString(), "registration_id" to registrationId.toString()),
            onDone = { _uiState.update { it.copy(codeCandidate = null, codeInput = "") } },
        )
    }

    private fun submitCheckin(fields: Map<String, String>, onDone: (() -> Unit)? = null) {
        _uiState.update { it.copy(isBusy = true) }
        viewModelScope.launch {
            when (val result = safeApiCall { api.checkinAction(fields) }) {
                is NetworkResult.Success -> {
                    val body = result.data
                    _uiState.update {
                        it.copy(
                            isBusy = false,
                            status = StatusMessage(body.message, !body.success),
                            announcement = if (body.success) body.announcement else null,
                        )
                    }
                    if (body.success) {
                        markLocalRowCheckedIn(body.registration_id, body.mode ?: "")
                        loadRecent()
                    }
                    onDone?.invoke()
                }
                is NetworkResult.Failure -> {
                    _uiState.update { it.copy(isBusy = false) }
                    handleFailure(result)
                    onDone?.invoke()
                }
            }
        }
    }

    private fun markLocalRowCheckedIn(registrationId: Int, mode: String) {
        _uiState.update { state ->
            state.copy(
                registrations = state.registrations.map { reg ->
                    if (reg.registration_id == registrationId) {
                        reg.copy(checked_in_at = "just now", checkin_mode = mode)
                    } else {
                        reg
                    }
                },
            )
        }
    }

    fun consumeAnnouncement() = _uiState.update { it.copy(announcement = null) }

    private fun handleFailure(failure: NetworkResult.Failure) {
        if (failure.isAuthError) {
            settings.clearSession()
            _uiState.update { it.copy(sessionExpired = true) }
            return
        }
        _uiState.update { it.copy(status = StatusMessage(failure.message, true)) }
    }
}
