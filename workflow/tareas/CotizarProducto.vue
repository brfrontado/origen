<template>
    <CotizarProducto :tokenProducto="tokenProducto" :tokenCotizacion="tokenCotizacion" :isPublic="false" :tokenLinking="tokenLinking"></CotizarProducto>
</template>

<script>
import toolbox from "@/toolbox";
import {config} from "@/config";

import CotizarProducto from "./CotizarComponent.vue";

export default {
    name: 'CotizarProductoWindow',
    components: {
        CotizarProducto
    },
    data() {
        return {
            tokenProducto: '',
            tokenCotizacion: '',
            tokenLinking: '',
        };
    },
    mounted() {
        this.tokenProducto = (typeof this.$route.params.tokenProducto !== 'undefined') ? this.$route.params.tokenProducto : '';
        this.tokenCotizacion = (typeof this.$route.params.tokenCotizacion !== 'undefined') ? this.$route.params.tokenCotizacion : '';
        this.tokenLinking = (typeof this.$route.query.tokenLinking !== 'undefined') ? this.$route.query.tokenLinking : '';
    },
    methods: {
    }
}
</script>
