<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AdminShell from '../components/AdminShell.vue'
import { api } from '../api'
import { can, currentUser, type AdminUser } from '../auth/access'
const users = ref<AdminUser[]>([]), catalog = ref<{key:string;name:string}[]>([])
const page = ref(1), lastPage = ref(1), loading = ref(false), saving = ref(false)
const error = ref(''), message = ref(''), editing = ref<number | null>(null)
const dialog = ref<HTMLDialogElement | null>(null), passwordDialog = ref<HTMLDialogElement | null>(null)
const passwordTarget = ref<AdminUser | null>(null), newPassword = ref(''), confirmation = ref('')
const draft = ref({name:'',email:'',is_active:true,is_super_user:false,permissions:[] as string[],password:'',password_confirmation:''})
const canManage = computed(() => can('users.manage') && currentUser.value?.is_super_user === true)
const groups = computed(() => [
  {name:'General',areas:['dashboard']}, {name:'Sales',areas:['plans','vouchers','payments']},
  {name:'Network',areas:['sessions','router']}, {name:'Support',areas:['support']},
  {name:'Administration',areas:['logs','users','businesses']},
].map(group => ({name:group.name,permissions:catalog.value.filter(p => group.areas.includes(p.key.split('.')[0]))})))
function failure(e:any) { return Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'The request failed. Please retry.' }
async function load(next = page.value) {
  loading.value=true; error.value=''
  try {
    const [list, permissions] = await Promise.all([api.get('/admin/users',{params:{page:next}}),api.get('/admin/permissions')])
    users.value=list.data.data; page.value=list.data.current_page; lastPage.value=list.data.last_page; catalog.value=permissions.data.data
  } catch(e) { error.value=String(failure(e)) } finally { loading.value=false }
}
function edit(user?:AdminUser) {
  editing.value=user?.id || null; error.value=''; message.value=''
  draft.value={name:user?.name || '',email:user?.email || '',is_active:user?.is_active ?? true,is_super_user:user?.is_super_user ?? false,permissions:user?.is_super_user ? [] : [...(user?.permissions || [])],password:'',password_confirmation:''}
  dialog.value?.showModal()
}
async function save() {
  saving.value=true; error.value=''
  try {
    const {password,password_confirmation,...details}=draft.value
    if(editing.value) await api.put(`/admin/users/${editing.value}`,details)
    else await api.post('/admin/users',{...details,password,password_confirmation})
    dialog.value?.close(); draft.value.password=''; draft.value.password_confirmation=''; message.value='User saved.'; await load()
  } catch(e) { error.value=String(failure(e)) } finally { saving.value=false }
}
async function status(user:AdminUser) {
  if(!window.confirm(`${user.is_active ? 'Disable' : 'Enable'} ${user.name}? Existing sessions will be revoked.`)) return
  saving.value=true; error.value=''; message.value=''
  try { await api.patch(`/admin/users/${user.id}/status`,{is_active:!user.is_active}); message.value='User status updated.'; await load() }
  catch(e) { error.value=String(failure(e)) } finally { saving.value=false }
}
function changePassword(user:AdminUser) {
  passwordTarget.value=user; newPassword.value=''; confirmation.value=''; error.value=''; message.value=''; passwordDialog.value?.showModal()
}
async function resetPassword() {
  saving.value=true; error.value=''
  try {
    await api.put(`/admin/users/${passwordTarget.value?.id}/password`,{password:newPassword.value,password_confirmation:confirmation.value})
    newPassword.value=''; confirmation.value=''; passwordDialog.value?.close(); message.value='Password changed. Existing sessions were revoked.'
  } catch(e) { error.value=String(failure(e)) } finally { saving.value=false }
}
onMounted(() => load())
</script>
<template>
  <AdminShell>
    <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h2">Users</h1><button v-if="canManage" class="btn btn-primary" :disabled="saving" @click="edit()">Create user</button></div>
    <p v-if="error && !dialog?.open && !passwordDialog?.open" role="alert" class="alert alert-danger">{{error}}</p>
    <p v-if="message" role="status" class="alert alert-success">{{message}}</p>
    <p v-if="!canManage" class="text-secondary">You can view safe user records. Only a Super User can change access.</p>
    <p v-if="loading" role="status">Loading users…</p>
    <div v-else class="table-responsive card"><table class="table align-middle mb-0"><thead><tr><th>Name / email</th><th>Status</th><th>Permissions</th><th v-if="canManage">Actions</th></tr></thead><tbody>
      <tr v-for="user in users" :key="user.id"><td>{{user.name}}<small class="d-block text-secondary">{{user.email}}</small><span v-if="user.is_super_user" class="badge text-bg-primary">Super User</span></td><td>{{user.is_active ? 'Active' : 'Disabled'}}</td><td>{{user.is_super_user ? 'All permissions' : user.permissions.join(', ') || 'No permissions'}}</td><td v-if="canManage"><div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-outline-primary" :disabled="saving" @click="edit(user)">Edit access</button><button class="btn btn-sm btn-outline-secondary" :disabled="saving" @click="status(user)">{{user.is_active ? 'Disable' : 'Enable'}}</button><button class="btn btn-sm btn-outline-secondary" :disabled="saving" @click="changePassword(user)">Change password</button></div></td></tr>
      <tr v-if="!users.length"><td colspan="4">No users found.</td></tr>
    </tbody></table></div>
    <div class="d-flex gap-2 align-items-center mt-3"><button class="btn btn-outline-secondary" :disabled="loading || page<=1" @click="load(page-1)">Previous</button><span>{{page}} / {{lastPage}}</span><button class="btn btn-outline-secondary" :disabled="loading || page>=lastPage" @click="load(page+1)">Next</button><button class="btn btn-outline-secondary" :disabled="loading" @click="load()">Refresh</button></div>
    <dialog ref="dialog" aria-labelledby="user-editor-title" @cancel="draft.password='';draft.password_confirmation=''">
      <form @submit.prevent="save"><h2 id="user-editor-title" class="h4">{{editing ? 'Edit user' : 'Create user'}}</h2><p v-if="error" role="alert" class="alert alert-danger">{{error}}</p>
        <label class="form-label d-block">Name<input v-model="draft.name" class="form-control" required maxlength="255"></label>
        <label class="form-label d-block">Email<input v-model="draft.email" class="form-control" type="email" required maxlength="255"></label>
        <template v-if="!editing"><label class="form-label d-block">Password<input v-model="draft.password" class="form-control" type="password" autocomplete="new-password" required minlength="12"></label><label class="form-label d-block">Confirm password<input v-model="draft.password_confirmation" class="form-control" type="password" autocomplete="new-password" required></label><p class="text-secondary">At least 12 characters, with uppercase, lowercase, numbers and symbols.</p></template>
        <label class="d-block mb-2"><input v-model="draft.is_active" type="checkbox"> Active</label>
        <label class="d-block mb-2"><input v-model="draft.is_super_user" type="checkbox"> Super User — full access and user administration</label>
        <p v-if="draft.is_super_user">Super Users have all permissions automatically.</p>
        <fieldset v-else v-for="group in groups" :key="group.name" class="mb-3"><legend class="h6">{{group.name}}</legend><label v-for="permission in group.permissions" :key="permission.key" class="d-block"><input v-model="draft.permissions" :value="permission.key" type="checkbox"> {{permission.name}}</label></fieldset>
        <p class="text-secondary">Security changes revoke this user's sessions. The last active Super User cannot be disabled or demoted.</p>
        <div class="d-flex gap-2"><button class="btn btn-primary" :disabled="saving">{{saving ? 'Saving…' : 'Save'}}</button><button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="dialog?.close();draft.password='';draft.password_confirmation=''">Cancel</button></div>
      </form>
    </dialog>
    <dialog ref="passwordDialog" aria-labelledby="password-title" @cancel="newPassword='';confirmation=''">
      <form @submit.prevent="resetPassword"><h2 id="password-title" class="h4">Change password for {{passwordTarget?.name}}</h2><p v-if="error" role="alert" class="alert alert-danger">{{error}}</p><p>At least 12 characters, with uppercase, lowercase, numbers and symbols.</p><label class="form-label d-block">New password<input v-model="newPassword" class="form-control" type="password" autocomplete="new-password" required minlength="12"></label><label class="form-label d-block">Confirm password<input v-model="confirmation" class="form-control" type="password" autocomplete="new-password" required></label><div class="d-flex gap-2"><button class="btn btn-primary" :disabled="saving">Change password</button><button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="passwordDialog?.close();newPassword='';confirmation=''">Cancel</button></div></form>
    </dialog>
  </AdminShell>
</template>
<style scoped>
dialog { width: min(640px, calc(100% - 24px)); max-height: 90vh; overflow: auto; border: 0; border-radius: 12px; padding: 24px; }
dialog::backdrop { background: rgb(0 0 0 / 45%); }
td { overflow-wrap: anywhere; }
</style>
