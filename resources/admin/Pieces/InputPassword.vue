<template>
    <!--
        A port of FluentSMTP 2.4's Pieces/InputPassword.vue, with the same class names so
        FluentSMTP's own stylesheet lays it out.

        A saved credential never reaches the browser: FluentSMTP sends its mask instead
        (FluentMailAdmin.masked_key). The mask is shown as a filled, disabled field with
        a Replace button, never as an editable value, so a stray keystroke cannot turn
        it into a "new" key that would be saved over the working one.
    -->
    <div class="fsm_input_password">
        <div v-if="isMasked" class="fsm_input_password_saved">
            <el-input
                :id="id"
                type="password"
                model-value="0000000000000000"
                disabled
            />
            <el-button size="small" :disabled="disabled" @click="startReplacing">
                {{ $t('Replace') }}
            </el-button>
        </div>

        <div v-else class="fsm_input_password_field">
            <el-input
                ref="field"
                :id="id"
                type="password"
                show-password
                :model-value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :name="fieldName"
                autocomplete="new-password"
                data-bwignore
                data-lpignore="true"
                data-1p-ignore
                data-form-type="other"
                @update:model-value="$emit('update:modelValue', $event)"
            />
            <el-button v-if="replacing" size="small" @click="cancelReplacing">
                {{ $t('Cancel') }}
            </el-button>
        </div>

        <p v-if="isMasked" class="small-help-text" style="font-size: 80%; margin: 3px 0 0 0">
            {{ $t('__SECRET_SAVED_HELP') }}
        </p>
        <p v-else-if="!disable_help" class="small-help-text" style="font-size: 80%; margin: 3px 0 0 0">
            {{ $t('This key is stored encrypted in your database using your WordPress security keys.') }}
        </p>
    </div>
</template>

<script>
import { ElButton, ElInput } from 'element-plus';

export default {
    name: 'InputPassword',
    props: ['modelValue', 'id', 'placeholder', 'disabled', 'disable_help'],
    emits: ['update:modelValue'],
    components: {
        ElButton,
        ElInput
    },
    data() {
        return {
            replacing: false
        };
    },
    computed: {
        maskedKey() {
            return window.FluentMailAdmin && window.FluentMailAdmin.masked_key;
        },

        isMasked() {
            return Boolean(this.maskedKey) && this.modelValue === this.maskedKey;
        },

        fieldName() {
            return 'fluentsmtp_' + (this.id || Math.random().toString(36).slice(2)) + '_secret';
        }
    },
    methods: {
        /*
         * Empty rather than the old value: an empty key is rejected by the validator,
         * so a Replace that is started and abandoned cannot save a broken connection.
         */
        startReplacing() {
            this.replacing = true;
            this.$emit('update:modelValue', '');
            this.$nextTick(() => {
                if (this.$refs.field) {
                    this.$refs.field.focus();
                }
            });
        },

        cancelReplacing() {
            this.replacing = false;
            this.$emit('update:modelValue', this.maskedKey);
        }
    }
};
</script>
