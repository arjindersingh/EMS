package com.ihgi.emscheckin.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.ihgi.emscheckin.data.dto.RecentAttendanceEntry

@Composable
fun RecentCheckinsList(entries: List<RecentAttendanceEntry>, modifier: Modifier = Modifier) {
    if (entries.isEmpty()) {
        Text(
            "No check-ins yet for this event.",
            style = MaterialTheme.typography.bodyMedium,
            modifier = modifier.padding(vertical = 8.dp),
        )
        return
    }

    LazyColumn(modifier = modifier) {
        items(entries, key = { it.attendance_id }) { entry ->
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 8.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(entry.name, style = MaterialTheme.typography.bodyLarge)
                Text(
                    "${entry.mode ?: "qr"} · ${entry.checked_in_at ?: ""}",
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            HorizontalDivider()
        }
    }
}
