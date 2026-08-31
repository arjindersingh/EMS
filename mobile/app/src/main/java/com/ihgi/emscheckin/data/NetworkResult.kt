package com.ihgi.emscheckin.data

import com.ihgi.emscheckin.data.dto.ApiMessage
import kotlinx.serialization.json.Json
import retrofit2.Response

sealed class NetworkResult<out T> {
    data class Success<T>(val data: T) : NetworkResult<T>()
    data class Failure(val message: String, val isAuthError: Boolean = false) : NetworkResult<Nothing>()
}

private val errorJson = Json { ignoreUnknownKeys = true }

/**
 * Unwraps a Retrofit [Response], turning a non-2xx response into a [NetworkResult.Failure]
 * with the server's JSON `message` field (falling back to a generic message), and flagging
 * 401s so callers can bounce back to the Settings/login screen.
 */
fun <T> Response<T>.toNetworkResult(): NetworkResult<T> {
    if (isSuccessful) {
        val body = body()
        return if (body != null) {
            NetworkResult.Success(body)
        } else {
            NetworkResult.Failure("The server returned an empty response.")
        }
    }

    val rawError = errorBody()?.string()
    val message = rawError?.let {
        runCatching { errorJson.decodeFromString(ApiMessage.serializer(), it).message }.getOrNull()
    }?.takeIf { it.isNotBlank() } ?: "Request failed (HTTP ${code()})."

    return NetworkResult.Failure(message, isAuthError = code() == 401)
}

suspend fun <T> safeApiCall(block: suspend () -> Response<T>): NetworkResult<T> {
    return try {
        block().toNetworkResult()
    } catch (exception: Exception) {
        NetworkResult.Failure(exception.message ?: "Network request failed.")
    }
}
