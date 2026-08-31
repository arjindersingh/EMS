package com.ihgi.emscheckin.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.ihgi.emscheckin.ui.checkin.StatusMessage

@Composable
fun StatusBanner(status: StatusMessage?, modifier: Modifier = Modifier) {
    val text = status?.text?.takeIf { it.isNotBlank() } ?: "Waiting for a QR scan, manual check-in, or check-in code."
    val isError = status?.isError == true

    val background = if (isError) {
        MaterialTheme.colorScheme.errorContainer
    } else if (status != null) {
        Color(0xFFDDF3E4)
    } else {
        MaterialTheme.colorScheme.surfaceVariant
    }
    val foreground = if (isError) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSurface

    Text(
        text = text,
        color = foreground,
        modifier = modifier
            .fillMaxWidth()
            .background(background, RoundedCornerShape(8.dp))
            .padding(12.dp),
    )
}
