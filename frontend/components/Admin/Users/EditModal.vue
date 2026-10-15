<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <div class="row g-3">
            <form-group-field
                id="edit_form_email"
                :input-attrs="{ disabled: isEditMode && !canManageUsers }"
                class="col-md-6"
                :field="r$.email"
                input-type="email"
                :label="$gettext('E-mail Address')"
            />

            <form-group-field
                id="edit_form_new_password"
                :input-attrs="{ disabled: isEditMode && !canManageUsers }"
                class="col-md-6"
                :field="r$.new_password"
                input-type="password"
                :label="$gettext('Password')"
            >
                <template
                    v-if="isEditMode"
                    #description
                >
                    {{ $gettext('Leave blank to use the current password.') }}
                </template>
            </form-group-field>

            <form-group-field
                id="edit_form_name"
                :input-attrs="{ disabled: isEditMode && !canManageUsers }"
                class="col-md-12"
                :field="r$.name"
                :label="$gettext('Display Name')"
            />

            <form-group-multi-check
                v-if="!isEditMode || canAssignRoles"
                id="edit_form_roles"
                class="col-md-12"
                :field="r$.roles.$self"
                :options="roles"
                :label="$gettext('Roles')"
            />
            <form-group-checkbox
                v-if="isSuperAdmin"
                id="edit_form_is_protected"
                class="col-md-12"
                :field="r$.is_protected"
                :label="$gettext('Protect from delegated administration')"
            />
        </div>
    </modal-form>
</template>

<script setup lang="ts">
import { email, required, requiredIf } from "@regle/rules";
import { computed, ref, toRef, useTemplateRef, watch } from "vue";
import ModalForm from "~/components/Common/ModalForm.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import {
    ApiAdminUserWithDetails,
    GlobalPermissions,
} from "~/entities/ApiInterfaces.ts";
import mergeExisting from "~/functions/mergeExisting.ts";
import {
    BaseEditModalEmits,
    BaseEditModalProps,
    useBaseEditModal,
} from "~/functions/useBaseEditModal";
import { useUserAllowed } from "~/functions/useUserAllowed.ts";
import { useTranslate } from "~/vendor/gettext";
import { isValidPassword, useAppRegle } from "~/vendor/regle.ts";

const props = defineProps<
    BaseEditModalProps & {
        roles: Record<number, string>;
    }
>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef("$modal");

// This value is needed higher up than it's defined, so it's synced back up here.
const editMode = ref(false);
const { userAllowed } = useUserAllowed();
const isSuperAdmin = userAllowed(GlobalPermissions.All);
const canManageUsers = userAllowed(GlobalPermissions.ManageUsers);
const canAssignRoles = userAllowed(GlobalPermissions.AssignRoles);

const { r$ } = useAppRegle(
    {
        email: "",
        new_password: "",
        name: "",
        roles: [] as number[],
        is_protected: false,
    },
    {
        email: { required, email },
        new_password: {
            isValidPassword,
            required: requiredIf(() => !editMode.value),
        },
    },
    {},
);

const {
    loading,
    error,
    isEditMode,
    clearContents,
    create,
    edit,
    doSubmit,
    close,
} = useBaseEditModal<Record<string, unknown>, ApiAdminUserWithDetails>(
    toRef(props, "createUrl"),
    emit,
    $modal,
    () => {
        r$.$reset({
            toOriginalState: true,
        });
    },
    (data) => {
        r$.$reset({
            toState: mergeExisting(r$.$value, {
                ...data,
                name: data.name ?? "",
                roles: (data.roles ?? []).map((role) => Number(role.id)),
                new_password: "",
            }),
        });
    },
    async () => {
        const { valid, data } = await r$.$validate();
        const payload: Record<string, unknown> = { ...data };
        if (!isSuperAdmin) delete payload.is_protected;
        if (isEditMode.value && !canAssignRoles) delete payload.roles;
        if (isEditMode.value && !canManageUsers) {
            return { valid, data: { roles: data.roles } };
        }
        return { valid, data: payload };
    },
);

watch(isEditMode, (newValue) => {
    editMode.value = newValue;
});

const { $gettext } = useTranslate();

const langTitle = computed(() => {
    return isEditMode.value ? $gettext("Edit User") : $gettext("Add User");
});

defineExpose({
    create,
    edit,
    close,
});
</script>
