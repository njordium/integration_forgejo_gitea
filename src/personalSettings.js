import { createApp } from 'vue'
import PersonalSettings from './components/PersonalSettings.vue'
import { applyGlobals } from './bootstrap.js'

const app = createApp(PersonalSettings)
applyGlobals(app)
app.mount('#forgejo_gitea_prefs')
