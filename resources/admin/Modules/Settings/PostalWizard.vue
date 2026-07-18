<template>
    <div class="fss_connection_wizard">
        <el-form :data="connection" label-position="top" autocomplete="off">
            <div class="fss_config_section">
                <h3 class="fs_config_title">{{ $t('Sender Settings') }}</h3>
                <el-row :gutter="20">
                    <el-col :md="12" :sm="24">
                        <el-form-item :label="$t('From Email')">
                            <error :error="errors.get('sender_email')"/>
                            <el-input
                                type="email"
                                :placeholder="$t('From Email')"
                                v-model="connection.sender_email"
                                autocomplete="off"
                            ></el-input>
                            <p style="color: red;" v-if="is_conflicted">
                                {{ $t('Another connection already exists with this email. Saving will overwrite it.') }}
                            </p>
                        </el-form-item>
                        <div>
                            <el-checkbox true-label="yes" false-label="no" v-model="connection.force_from_email">
                                {{ $t('Force From Email (Recommended Settings: Enable)') }}
                            </el-checkbox>
                        </div>
                        <div>
                            <el-checkbox true-label="yes" false-label="no" v-model="connection.return_path">
                                {{ $t('Set the return-path to match the From Email') }}
                            </el-checkbox>
                        </div>
                    </el-col>
                    <el-col :md="12" :sm="24">
                        <el-form-item :label="$t('From Name')">
                            <el-input
                                type="text"
                                :placeholder="$t('From Name')"
                                v-model="connection.sender_name"
                            ></el-input>
                            <error :error="errors.get('sender_name')"/>
                        </el-form-item>
                        <el-checkbox true-label="yes" false-label="no" v-model="connection.force_from_name">
                            {{ $t('Force Sender Name') }}
                        </el-checkbox>
                    </el-col>
                </el-row>
            </div>

            <div class="fss_config_section">
                <postal :connection="connection" :errors="errors" :is_new="is_new"/>
            </div>

            <el-alert
                v-if="!appVars.crypto_available"
                type="warning"
                :closable="false"
                style="margin: 15px 0"
                :title="$t('The OpenSSL PHP extension is not available, so the API key will be stored unencrypted. Consider using the wp-config.php option.')"
            />

            <div class="fss_wizard_actions" style="margin-top: 20px">
                <el-button v-loading="saving" @click="saveConnectionSettings" type="success">
                    {{ $t('Save Connection Settings') }}
                </el-button>
                <el-button v-loading="testing" @click="testConnection" plain>
                    {{ $t('Test Connection') }}
                </el-button>
                <el-button @click="$emit('cancel')" type="text">
                    {{ $t('Cancel') }}
                </el-button>
            </div>

            <el-alert v-if="has_error && api_error" style="margin-top: 20px" type="error" :closable="false">
                {{ api_error }}
            </el-alert>
        </el-form>
    </div>
</template>

<script>
import Postal from './Partials/Providers/Postal.vue';
import Error from '@/Pieces/Error';
import Errors from '@/Bits/Errors';

export default {
    name: 'PostalWizard',
    props: ['connection', 'is_new', 'connections', 'connection_key'],
    components: {
        Postal,
        Error
    },
    data() {
        return {
            saving: false,
            testing: false,
            errors: new Errors(),
            api_error: '',
            has_error: false
        };
    },
    computed: {
        is_conflicted() {
            if (!this.connections) {
                return false;
            }
            let conflicted = false;
            Object.keys(this.connections).forEach(key => {
                const existing = this.connections[key];
                if (
                    key !== this.connection_key &&
                    existing.provider_settings &&
                    existing.provider_settings.sender_email === this.connection.sender_email
                ) {
                    conflicted = true;
                }
            });
            return conflicted;
        }
    },
    methods: {
        saveConnectionSettings() {
            this.saving = true;
            this.resetErrors();
            this.$post('save', {
                connection: this.connection,
                connection_key: this.connection_key || ''
            })
                .then(response => {
                    this.$notify.success(response.data.message);
                    this.$emit('saved', response.data);
                })
                .fail(error => {
                    this.recordFailure(error);
                })
                .always(() => {
                    this.saving = false;
                });
        },
        testConnection() {
            this.testing = true;
            this.resetErrors();
            this.$post('test_connection', {
                connection: this.connection,
                connection_key: this.connection_key || ''
            })
                .then(response => {
                    this.$notify.success(response.data.message);
                })
                .fail(error => {
                    this.recordFailure(error, this.$t('The Postal connection test failed.'));
                })
                .always(() => {
                    this.testing = false;
                });
        },
        resetErrors() {
            this.errors.clear();
            this.api_error = '';
            this.has_error = false;
        },
        recordFailure(error, fallbackMessage) {
            const data = (error && error.responseJSON && error.responseJSON.data) || {};
            if (data.message) {
                this.api_error = data.message;
            } else {
                this.errors.record(data);
                this.api_error = fallbackMessage || this.$t('Please correct the highlighted fields.');
            }
            this.has_error = true;
            this.$notify.error({ title: this.$t('Error'), message: this.api_error });
        }
    }
};
</script>
