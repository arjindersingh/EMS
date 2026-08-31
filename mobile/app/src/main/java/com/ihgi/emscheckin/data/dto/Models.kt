package com.ihgi.emscheckin.data.dto

import kotlinx.serialization.Serializable

@Serializable
data class ApiMessage(
    val success: Boolean = false,
    val message: String = "",
)

@Serializable
data class AdminInfo(
    val admin_user_id: Int = 0,
    val name: String = "",
    val username: String = "",
    val role: String = "",
)

@Serializable
data class LoginResponse(
    val success: Boolean = false,
    val message: String = "",
    val token: String? = null,
    val admin: AdminInfo? = null,
)

@Serializable
data class EventDto(
    val event_id: Int,
    val event_title: String,
    val start_date: String? = null,
)

@Serializable
data class EventsResponse(
    val success: Boolean = false,
    val events: List<EventDto> = emptyList(),
)

@Serializable
data class RegistrationDto(
    val registration_id: Int,
    val name: String = "",
    val designation: String? = null,
    val institution_name: String? = null,
    val mobile: String? = null,
    val official_email: String? = null,
    val checked_in_at: String? = null,
    val checkin_mode: String? = null,
)

@Serializable
data class RegistrationsResponse(
    val success: Boolean = false,
    val registrations: List<RegistrationDto> = emptyList(),
)

@Serializable
data class CandidateDto(
    val registration_id: Int,
    val event_id: Int = 0,
    val name: String = "",
    val designation: String? = null,
    val institution_name: String? = null,
    val mobile: String? = null,
    val official_email: String? = null,
    val whatsapp_number: String? = null,
    val approval_status: String? = null,
    val pass_code: String? = null,
)

@Serializable
data class AttendanceDto(
    val attendance_id: Int = 0,
    val event_id: Int = 0,
    val registration_id: Int = 0,
    val checked_in_at: String? = null,
    val checked_in_by: String? = null,
    val mode: String? = null,
    val whatsapp_sent: Int = 0,
)

@Serializable
data class CheckinActionResponse(
    val success: Boolean = false,
    val message: String = "",
    val announcement: String? = null,
    val name: String? = null,
    val mode: String? = null,
    val registration_id: Int = 0,
    val candidate: CandidateDto? = null,
    val attendance: AttendanceDto? = null,
)

@Serializable
data class RecentAttendanceEntry(
    val attendance_id: Int = 0,
    val registration_id: Int = 0,
    val name: String = "",
    val mode: String? = null,
    val checked_in_at: String? = null,
    val whatsapp_sent: Int = 0,
)

@Serializable
data class RecentAttendanceResponse(
    val success: Boolean = false,
    val message: String = "",
    val recent_attendance: List<RecentAttendanceEntry> = emptyList(),
)
