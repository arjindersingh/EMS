package com.ihgi.emscheckin

import android.app.Application
import com.ihgi.emscheckin.data.SettingsRepository

class EmsApp : Application() {

    val settings: SettingsRepository by lazy { SettingsRepository(this) }
}
