package com.ihgi.emscheckin.util

import android.content.Context
import android.speech.tts.TextToSpeech
import java.util.Locale

/**
 * Thin wrapper around Android's built-in TextToSpeech engine, used to speak the
 * welcome announcement returned by the check-in API (parity with the web page's
 * use of the browser's speechSynthesis API).
 */
class Tts(context: Context) {

    private var engine: TextToSpeech? = null
    private var ready = false

    init {
        engine = TextToSpeech(context.applicationContext) { status ->
            ready = status == TextToSpeech.SUCCESS
            if (ready) {
                engine?.language = Locale.Builder().setLanguage("en").setRegion("IN").build()
            }
        }
    }

    fun speak(text: String) {
        if (text.isBlank()) return
        val tts = engine ?: return
        if (!ready) return
        tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, "ems_checkin_announcement")
    }

    fun shutdown() {
        engine?.stop()
        engine?.shutdown()
        engine = null
    }
}
