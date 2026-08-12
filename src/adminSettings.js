import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { applyGlobals } from './bootstrap.js'

const app = createApp(AdminSettings)
applyGlobals(app)
app.mount('#forgejo_gitea_prefs')
