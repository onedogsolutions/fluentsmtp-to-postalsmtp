<template>
    <div>
        <h3 class="fs_config_title">{{ $t('Postal API Settings') }}</h3>

        <el-form-item>
            <label for="postal-server-url">
                {{ $t('Postal Server URL') }}
            </label>
            <el-input
                id="postal-server-url"
                type="url"
                v-model="connection.server_url"
                placeholder="https://postal.example.com"
            />
            <error :error="errors.get('server_url')"/>
            <span class="small-help-text" style="display:block;margin-top:3px">
                {{ $t('The base URL of your Postal server. Mail is sent to /api/v1/send/raw on this host over HTTPS.') }}
            </span>
        </el-form-item>

        <el-radio-group size="mini" v-model="connection.key_store">
            <el-radio-button label="db">{{ $t('Store API Keys in DB') }}</el-radio-button>
            <el-radio-button label="wp_config">{{ $t('Store API Keys in Config File') }}</el-radio-button>
        </el-radio-group>

        <template v-if="connection.key_store == 'db'">
            <el-form-item>
                <label for="postal-key">
                    {{ $t('Server API Key') }}
                </label>
                <InputPassword
                    id="postal-key"
                    v-model="connection.api_key"
                    :placeholder="has_api_key ? '••••••••••••••••' : ''"
                />
                <error :error="errors.get('api_key')"/>
                <span class="small-help-text" style="display:block;margin-top:3px">
                    {{ $t('Create this under your Postal server: Credentials → New Credential → type “API”.') }}
                </span>
            </el-form-item>
        </template>

        <div class="fss_condesnippet_wrapper" v-else-if="connection.key_store == 'wp_config'">
            <el-form-item>
                <label>{{ $t('Add the following line to your wp-config.php file:') }}</label>
                <div class="code_snippet">
                    <textarea readonly style="width: 100%;">define( 'FLUENTSMTP_POSTAL_API_KEY', '********************' );</textarea>
                </div>
                <error :error="errors.get('api_key')"/>
            </el-form-item>
        </div>

        <el-row class="fsmtp_compact" :gutter="30">
            <el-col :md="12" :sm="24">
                <el-form-item :label="$t('Verify SSL Certificate')">
                    <el-checkbox
                        true-label="yes"
                        false-label="no"
                        v-model="connection.verify_ssl"
                    >
                        {{ $t('Verify the Postal server TLS certificate (recommended).') }}
                        <el-tooltip effect="dark" placement="top-start">
                            <div slot="content" style="max-width:300px">
                                {{ $t('Leave enabled for Let\'s Encrypt / Caddy certificates. Disable only for self-signed certificates on isolated lab servers.') }}
                            </div>
                            <i class="el-icon-info"></i>
                        </el-tooltip>
                    </el-checkbox>
                    <p style="color:#E6A23C;margin:4px 0 0;" v-if="connection.verify_ssl === 'no'">
                        {{ $t('TLS certificate verification is disabled. The connection is still encrypted, but not authenticated.') }}
                    </p>
                </el-form-item>
            </el-col>
            <el-col :md="12" :sm="24">
                <el-form-item :label="$t('Treat as Bounce')">
                    <el-checkbox
                        true-label="yes"
                        false-label="no"
                        v-model="connection.bounce"
                    >
                        {{ $t('Mark messages from this connection as bounces in Postal.') }}
                        <el-tooltip effect="dark" placement="top-start">
                            <div slot="content" style="max-width:300px">
                                {{ $t('Most sites should leave this off. Enable only if this connection exclusively sends bounce notifications.') }}
                            </div>
                            <i class="el-icon-info"></i>
                        </el-tooltip>
                    </el-checkbox>
                </el-form-item>
            </el-col>
        </el-row>

        <span class="small-help-text" style="display:block;margin-top:5px">
            {{ $t('The sending domain must be verified on your Postal server, otherwise Postal rejects the message as an unauthenticated From address.') }}
        </span>
    </div>
</template>

<script>
import InputPassword from '@/Pieces/InputPassword';
import Error from '@/Pieces/Error';

export default {
    name: 'Postal',
    props: ['connection', 'errors', 'provider', 'is_new'],
    components: {
        InputPassword,
        Error
    },
    computed: {
        has_api_key() {
            return Boolean(this.connection && this.connection.has_api_key);
        }
    }
};
</script>
