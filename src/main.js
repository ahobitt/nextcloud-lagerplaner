// SPDX-License-Identifier: AGPL-3.0-or-later
import { createApp } from 'vue'
import App from './App.vue'
import { startRouter } from './router.js'

startRouter()
createApp(App).mount('#content')
