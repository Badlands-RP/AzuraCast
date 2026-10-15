<template>
    <tab :label="$gettext('Global Permissions')">
        <div class="row g-3">
            <form-group-field
                id="edit_form_name"
                class="col-md-12"
                :field="r$.name"
                :label="$gettext('Role Name')"
            />

            <form-group-multi-check
                id="edit_form_global_permissions"
                class="col-md-12"
                :field="r$.permissions.global.$self"
                :options="globalPermissions"
                stacked
                :label="$gettext('Global Permissions')"
                :description="$gettext('Users with this role will have these permissions across the entire installation.')"
            />
            <form-group-multi-check
                v-if="isSuperAdmin"
                id="edit_form_assignable_roles"
                class="col-md-12"
                v-model="form.assignable_roles"
                :options="assignableRoles"
                :label="$gettext('Roles this administrator may assign')"
                :description="$gettext('Only explicitly selected roles may be delegated. Their permissions must also be within the acting user’s own authority. User administration and API key administration cannot be delegated through these roles.')"
            />
            <form-group-checkbox
                v-if="isSuperAdmin"
                id="edit_form_role_is_protected"
                class="col-md-12"
                v-model="form.is_protected"
                :label="$gettext('Protect from delegated administration')"
            />
        </div>
    </tab>
</template>

<script setup lang="ts">
import { required } from "@regle/rules";
import { PermissionsRecord } from "~/components/Admin/Permissions/EditModal.vue";
import Tab from "~/components/Common/Tab.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import { GlobalPermissions } from "~/entities/ApiInterfaces.ts";
import { SimpleFormOptionInput } from "~/functions/objectToFormOptions.ts";
import { useUserAllowed } from "~/functions/useUserAllowed.ts";
import { useAppScopedRegle } from "~/vendor/regle.ts";

defineProps<{
    globalPermissions: SimpleFormOptionInput;
    assignableRoles: SimpleFormOptionInput;
}>();

const form = defineModel<PermissionsRecord>("form", { required: true });
const { userAllowed } = useUserAllowed();
const isSuperAdmin = userAllowed(GlobalPermissions.All);

const { r$ } = useAppScopedRegle(
    form,
    {
        name: { required },
        permissions: {
            global: {},
        },
    },
    {
        namespace: "admin-permissions",
    },
);
</script>
