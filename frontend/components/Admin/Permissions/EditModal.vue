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
        <tabs content-class="mt-3">
            <admin-permissions-global-form
                v-model:form="form"
                :global-permissions="globalPermissions"
                :assignable-roles="assignableRoles"
            />

            <admin-permissions-station-form
                v-model:form="form"
                :stations="stations"
                :station-permissions="stationPermissions"
                :station-permissions-by-station="stationPermissionsByStation"
            />
        </tabs>
    </modal-form>
</template>

<script setup lang="ts">
import { computed, toRef, useTemplateRef } from "vue";
import AdminPermissionsGlobalForm from "~/components/Admin/Permissions/Form/GlobalForm.vue";
import AdminPermissionsStationForm from "~/components/Admin/Permissions/Form/StationForm.vue";
import ModalForm from "~/components/Common/ModalForm.vue";
import Tabs from "~/components/Common/Tabs.vue";
import {
    ApiAdminRoleStationPermission,
    GlobalPermissions,
    StationPermissions,
} from "~/entities/ApiInterfaces.ts";
import mergeExisting from "~/functions/mergeExisting.ts";
import {
    BaseEditModalEmits,
    BaseEditModalProps,
    useBaseEditModal,
} from "~/functions/useBaseEditModal";
import { useResettableRef } from "~/functions/useResettableRef.ts";
import { useUserAllowed } from "~/functions/useUserAllowed.ts";
import { useTranslate } from "~/vendor/gettext";
import { useAppCollectScope } from "~/vendor/regle.ts";

const props = defineProps<
    BaseEditModalProps & {
        stations: Record<number, string>;
        globalPermissions: Record<GlobalPermissions, string>;
        stationPermissions: Record<StationPermissions, string>;
        assignableRoles: Record<number, string>;
        stationPermissionsByStation: Record<number, Record<string, string>>;
    }
>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef("$modal");

export type PermissionsRecord = {
    name: string;
    assignable_roles: number[];
    is_protected: boolean;
    permissions: {
        global: GlobalPermissions[];
        station: ApiAdminRoleStationPermission[];
    };
};

const { record: form, reset: resetFormRef } =
    useResettableRef<PermissionsRecord>({
        name: "",
        assignable_roles: [],
        is_protected: false,
        permissions: {
            global: [],
            station: [],
        },
    });

const { r$ } = useAppCollectScope("admin-permissions");
const { userAllowed } = useUserAllowed();

const {
    loading,
    error,
    isEditMode,
    clearContents,
    create,
    edit,
    doSubmit,
    close,
} = useBaseEditModal<Partial<PermissionsRecord>>(
    toRef(props, "createUrl"),
    emit,
    $modal,
    () => {
        resetFormRef();
        r$.$reset();
    },
    (data) => {
        form.value = mergeExisting<PermissionsRecord>(form.value, data);
        r$.$reset();
    },
    async () => {
        const { valid } = await r$.$validate();
        const data = userAllowed(GlobalPermissions.All)
            ? form.value
            : {
                  name: form.value.name,
                  permissions: form.value.permissions,
              };
        return { valid, data };
    },
);

const { $gettext } = useTranslate();

const langTitle = computed(() => {
    return isEditMode.value ? $gettext("Edit Role") : $gettext("Add Role");
});

defineExpose({
    create,
    edit,
    close,
});
</script>
