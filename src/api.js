// SPDX-License-Identifier: AGPL-3.0-or-later
import axios from '@nextcloud/axios'
import { t } from './l10n.js'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/lagerplaner/api' + path)
const data = (request) => request.then((response) => response.data)

export const listCamps = () => data(axios.get(url('/camps')))
export const getCamp = (id) => data(axios.get(url(`/camps/${id}`)))
export const deleteCamp = (id) => data(axios.delete(url(`/camps/${id}`)))
export const getProgram = (id) => data(axios.get(url(`/camps/${id}/program`)))
export const getTasks = (id) => data(axios.get(url(`/camps/${id}/tasks`)))
export const getMeals = (id) => data(axios.get(url(`/camps/${id}/meals`)))

export const wizardDefaults = () => data(axios.get(url('/wizard/defaults')))
export const previewDraft = (input) => data(axios.post(url('/wizard/preview'), { input }))
export const createCamp = (input) => data(axios.post(url('/wizard/camps'), { input }))
export const lastInput = (id) => data(axios.get(url(`/camps/${id}/wizard`)))
export const rerunPreview = (id, input) => data(axios.post(url(`/camps/${id}/wizard/preview`), { input }))
export const rerunApply = (id, input, token, groupDecisions = {}) => data(axios.post(url(`/camps/${id}/wizard/apply`), { input, token, groupDecisions }))
export const getProgramConflicts = (campId) => data(axios.get(url(`/camps/${campId}/program/conflicts`)))

export const confirmProgramItem = (id) => data(axios.post(url(`/program/${id}/confirm`)))
export const updateProgramItem = (id, fields) => data(axios.put(url(`/program/${id}`), { fields }))
export const deleteProgramItem = (id) => data(axios.delete(url(`/program/${id}`)))
export const updateTask = (id, fields) => data(axios.put(url(`/tasks/${id}`), { fields }))

/** Verständliche Meldung aus einer fehlgeschlagenen Anfrage. */
export function errorMessage(error) {
	return error?.response?.data?.message
		?? t('lagerplaner', 'The server could not be reached. Check your connection and try again.')
}

/** Feldbezogene Fehler (HTTP 422) als Objekt Feldpfad => Meldung. */
export function fieldErrors(error) {
	return error?.response?.status === 422 ? (error.response.data.errors ?? {}) : {}
}

export const isOutdated = (error) => error?.response?.status === 409

export const getDeletedProgram = (campId) => data(axios.get(url(`/camps/${campId}/program/deleted`)))
export const createProgramItem = (campId, fields) => data(axios.post(url(`/camps/${campId}/program`), { fields }))
export const restoreProgramItem = (id) => data(axios.post(url(`/program/${id}/restore`)))
export const createTask = (campId, fields) => data(axios.post(url(`/camps/${campId}/tasks`), { fields }))
export const deleteTask = (id) => data(axios.delete(url(`/tasks/${id}`)))

export const searchGames = (params) => data(axios.get(url('/games'), { params }))
export const getGame = (id) => data(axios.get(url(`/games/${id}`)))
export const getGameFacets = () => data(axios.get(url('/games/facets')))
export const getProgramGames = (campId) => data(axios.get(url(`/camps/${campId}/program/games`)))
export const attachGame = (programItemId, gameId) => data(axios.post(url(`/program/${programItemId}/games`), { gameId }))
export const detachGame = (id) => data(axios.delete(url(`/program-games/${id}`)))

export const getCatering = (campId) => data(axios.get(url(`/camps/${campId}/catering`)))
export const saveNeeds = (campId, payload) => data(axios.put(url(`/camps/${campId}/catering/needs`), payload))
export const getMealPlan = (mealId) => data(axios.get(url(`/meals/${mealId}/plan`)))
export const saveMealPlan = (mealId, payload) => data(axios.put(url(`/meals/${mealId}/plan`), payload))
export const copyMealPlan = (mealId, scope, overwrite = false) => data(axios.post(url(`/meals/${mealId}/plan/copy`), { scope, overwrite }))

export const getMembers = (campId) => data(axios.get(url(`/camps/${campId}/members`)))
export const searchMembers = (campId, q) => data(axios.get(url(`/camps/${campId}/members/search`), { params: { q } }))
export const addMember = (campId, type, memberId, roles) => data(axios.post(url(`/camps/${campId}/members`), { type, memberId, roles }))
export const updateMember = (id, roles) => data(axios.put(url(`/members/${id}`), { roles }))
export const removeMember = (id) => data(axios.delete(url(`/members/${id}`)))

export const getRecipes = (campId) => data(axios.get(url(`/camps/${campId}/recipes`)))
export const getRecipe = (id, portions = null) => data(axios.get(url(`/recipes/${id}`), { params: portions ? { portions } : {} }))
export const createRecipe = (campId, recipe, allergensChecked) => data(axios.post(url(`/camps/${campId}/recipes`), { recipe, allergensChecked }))
export const updateRecipe = (id, recipe, allergensChecked) => data(axios.put(url(`/recipes/${id}`), { recipe, allergensChecked }))
export const confirmRecipeAllergens = (id) => data(axios.post(url(`/recipes/${id}/allergens-checked`)))
export const deleteRecipe = (id) => data(axios.delete(url(`/recipes/${id}`)))
export const getRecipeLibrary = (campId) => data(axios.get(url(`/camps/${campId}/recipes/library`)))
export const importRecipes = (campId, keys) => data(axios.post(url(`/camps/${campId}/recipes/import`), { keys }))

export const getShopping = (campId) => data(axios.get(url(`/camps/${campId}/shopping`)))
export const addShoppingTrip = (campId, date, label) => data(axios.post(url(`/camps/${campId}/shopping/trips`), { date, label }))
export const updateShoppingTrip = (id, date, label) => data(axios.put(url(`/shopping/trips/${id}`), { date, label }))
export const deleteShoppingTrip = (id) => data(axios.delete(url(`/shopping/trips/${id}`)))
export const setShoppingLine = (tripId, key, purchased) => data(axios.put(url(`/shopping/trips/${tripId}/lines`), { key, purchased }))
export const setShoppingStock = (campId, key, quantity, unit) => data(axios.put(url(`/camps/${campId}/shopping/stock`), { key, quantity, unit }))
export const addShoppingExtra = (tripId, name, amount, category) => data(axios.post(url(`/shopping/trips/${tripId}/extras`), { name, amount, category }))
export const setShoppingExtra = (id, purchased) => data(axios.put(url(`/shopping/extras/${id}`), { purchased }))
export const deleteShoppingExtra = (id) => data(axios.delete(url(`/shopping/extras/${id}`)))

export const getPeople = (campId) => data(axios.get(url(`/camps/${campId}/people`)))
export const claimTask = (id) => data(axios.post(url(`/tasks/${id}/claim`)))
export const getMaterial = (campId) => data(axios.get(url(`/camps/${campId}/material`)))
export const createMaterial = (campId, material) => data(axios.post(url(`/camps/${campId}/material`), { material }))
export const updateMaterial = (id, material) => data(axios.put(url(`/material/${id}`), { material }))
export const setMaterialStatus = (id, status) => data(axios.put(url(`/material/${id}/status`), { status }))
export const deleteMaterial = (id) => data(axios.delete(url(`/material/${id}`)))
export const addMaterialUse = (id, programItemId, quantity) => data(axios.post(url(`/material/${id}/uses`), { programItemId, quantity }))
export const updateMaterialUse = (id, quantity) => data(axios.put(url(`/material-uses/${id}`), { quantity }))
export const removeMaterialUse = (id) => data(axios.delete(url(`/material-uses/${id}`)))
export const resolveMaterialSuggestion = (campId, ref, take) => data(axios.post(url(`/camps/${campId}/material/suggestions`), { ref, take }))

export const searchSites = (params) => data(axios.get(url('/sites'), { params }))
export const getSite = (id) => data(axios.get(url(`/sites/${id}`)))
export const createSite = (site) => data(axios.post(url('/sites'), { site }))
export const updateSite = (id, site) => data(axios.put(url(`/sites/${id}`), { site }))
export const deleteSite = (id) => data(axios.delete(url(`/sites/${id}`)))
export const getShortlist = (campId) => data(axios.get(url(`/camps/${campId}/sites`)))
export const addToShortlist = (campId, siteId) => data(axios.post(url(`/camps/${campId}/sites`), { siteId }))
export const updateShortlist = (entryId, changes) => data(axios.put(url(`/camp-sites/${entryId}`), { changes }))
export const removeFromShortlist = (entryId) => data(axios.delete(url(`/camp-sites/${entryId}`)))
export const adoptSite = (entryId) => data(axios.post(url(`/camp-sites/${entryId}/adopt`)))

export const getExport = (campId, type, param = '') => data(axios.get(url(`/camps/${campId}/export/${type}`), { params: { param } }))
export const listTemplates = () => data(axios.get(url('/templates')))
export const getTemplate = (id) => data(axios.get(url(`/templates/${id}`)))
export const saveItemTemplate = (programItemId, name, shared, replaceId = null) => data(axios.post(url('/templates/item'), { programItemId, name, shared, replaceId }))
export const saveDayTemplate = (campId, day, name, shared, replaceId = null) => data(axios.post(url('/templates/day'), { campId, day, name, shared, replaceId }))
export const updateTemplate = (id, changes) => data(axios.put(url(`/templates/${id}`), { changes }))
export const deleteTemplate = (id) => data(axios.delete(url(`/templates/${id}`)))
export const applyTemplate = (id, campId, day, startTime = '') => data(axios.post(url(`/templates/${id}/apply`), { campId, day, startTime }))

export const getCookbookLibrary = (campId) => data(axios.get(url('/cookbook'), { params: { campId } }))
export const previewCookbookRecipe = (id) => data(axios.get(url(`/cookbook/${id}`)))
export const importCookbookRecipe = (campId, cookbookId) => data(axios.post(url(`/camps/${campId}/cookbook`), { cookbookId }))
export const getCookbookUpdates = (campId) => data(axios.get(url(`/camps/${campId}/cookbook/updates`)))
export const refreshFromCookbook = (recipeId) => data(axios.post(url(`/recipes/${recipeId}/cookbook/refresh`)))
export const exportToCookbook = (recipeId) => data(axios.post(url(`/recipes/${recipeId}/cookbook`)))

export const cancelMeal = (mealId, cancelled) => data(axios.put(url(`/meals/${mealId}/cancelled`), { cancelled }))
export const setMealDish = (mealId, recipeId) => data(axios.put(url(`/meals/${mealId}/dish`), { recipeId }))
export const proposeMenu = (campId) => data(axios.post(url(`/camps/${campId}/menu/propose`)))
export const getDishes = (campId) => data(axios.get(url(`/camps/${campId}/dishes`)))

export const moveScheduleEntry = (campId, kind, entryId, day, time) => data(axios.post(url(`/camps/${campId}/schedule/move`), { kind, entryId, day, time }))
export const restoreSchedule = (campId, changes) => data(axios.post(url(`/camps/${campId}/schedule/restore`), { changes }))
export const setMealTimes = (campId, type, time) => data(axios.put(url(`/camps/${campId}/meal-times`), { type, time }))

export const updateTribeHome = (groupId, input) => data(axios.put(url(`/homes/${encodeURIComponent(groupId)}`), { input }))

export const correctSite = (id, site, evidenceUrl = '') => data(axios.put(url(`/sites/${id}/correction`), { site, evidenceUrl }))

export const sendSiteFeedback = (siteId, kind, values, message) => data(axios.post(url(`/sites/${siteId}/feedback`), { kind, values, message }))
export const reportMissingSite = (values, message) => data(axios.post(url('/sites/feedback'), { values, message }))
