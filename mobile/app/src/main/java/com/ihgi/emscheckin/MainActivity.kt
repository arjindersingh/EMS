package com.ihgi.emscheckin

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.ihgi.emscheckin.ui.checkin.CheckinScreen
import com.ihgi.emscheckin.ui.settings.SettingsScreen
import com.ihgi.emscheckin.ui.theme.EmsCheckinTheme

private object Routes {
    const val SETTINGS = "settings"
    const val CHECKIN = "checkin"
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val startDestination = if ((application as EmsApp).settings.isLoggedIn) Routes.CHECKIN else Routes.SETTINGS

        setContent {
            EmsCheckinTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    EmsNavHost(startDestination)
                }
            }
        }
    }
}

@Composable
private fun EmsNavHost(startDestination: String) {
    val navController: NavHostController = rememberNavController()

    NavHost(navController = navController, startDestination = startDestination) {
        composable(Routes.SETTINGS) {
            SettingsScreen(
                onLoggedIn = {
                    navController.navigate(Routes.CHECKIN) {
                        popUpTo(Routes.SETTINGS) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.CHECKIN) {
            CheckinScreen(
                onSessionExpired = {
                    navController.navigate(Routes.SETTINGS) {
                        popUpTo(Routes.CHECKIN) { inclusive = true }
                    }
                },
            )
        }
    }
}
