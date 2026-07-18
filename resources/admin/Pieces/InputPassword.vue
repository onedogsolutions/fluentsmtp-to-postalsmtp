<template>
    <div>
        <el-input
            :id="id"
            :type="type"
            :value="value"
            :placeholder="placeholder"
            :disabled="disabled"
            :name="fieldName"
            autocomplete="new-password"
            data-bwignore
            data-lpignore="true"
            data-1p-ignore
            data-form-type="other"
            @input="$emit('input', $event)"
        >
            <i
                slot="suffix"
                class="el-input__icon"
                :class="type === 'password' ? 'el-icon-view' : 'el-icon-loading'"
                style="cursor:pointer"
                @click="toggle"
            ></i>
        </el-input>
        <p v-if="!disable_help" class="small-help-text" style="font-size: 80%; margin: 3px 0 0 0">
            {{ $t('This key is stored encrypted in your database using your WordPress security keys.') }}
        </p>
    </div>
</template>

<script>
export default {
    name: 'InputPassword',
    props: ['value', 'id', 'placeholder', 'disabled', 'disable_help'],
    data() {
        return {
            type: 'password'
        };
    },
    computed: {
        fieldName() {
            return 'fluentsmtp_' + (this.id || Math.random().toString(36).slice(2)) + '_secret';
        }
    },
    methods: {
        toggle() {
            this.type = this.type === 'text' ? 'password' : 'text';
        }
    }
};
</script>
