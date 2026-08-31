package com.ihgi.emscheckin.data

import com.ihgi.emscheckin.data.dto.CheckinActionResponse
import com.ihgi.emscheckin.data.dto.EventsResponse
import com.ihgi.emscheckin.data.dto.LoginResponse
import com.ihgi.emscheckin.data.dto.RecentAttendanceResponse
import com.ihgi.emscheckin.data.dto.RegistrationsResponse
import retrofit2.Response
import retrofit2.http.Field
import retrofit2.http.FieldMap
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

/**
 * Mirrors the JSON contract of the PHP endpoints under /api on the EMS server
 * (see api/login.php, api/events.php, api/registrations.php, api/checkin.php).
 */
interface ApiService {

    @FormUrlEncoded
    @POST("login.php")
    suspend fun login(
        @Field("username") username: String,
        @Field("password") password: String,
        @Field("device_label") deviceLabel: String,
    ): Response<LoginResponse>

    @POST("logout.php")
    suspend fun logout(): Response<Unit>

    @GET("events.php")
    suspend fun getEvents(): Response<EventsResponse>

    @GET("registrations.php")
    suspend fun getRegistrations(@Query("event_id") eventId: Int): Response<RegistrationsResponse>

    @FormUrlEncoded
    @POST("checkin.php")
    suspend fun checkinAction(@FieldMap fields: Map<String, String>): Response<CheckinActionResponse>

    @FormUrlEncoded
    @POST("checkin.php")
    suspend fun recentAttendance(@FieldMap fields: Map<String, String>): Response<RecentAttendanceResponse>
}
