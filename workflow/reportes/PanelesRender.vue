<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardBody>
                    <div v-show="cargando" class="text-muted text-center my-5">
                        Cargando panel...
                    </div>
                    <div v-show="!cargando">
                        <iframe class="iframeCwReports" :src="url" frameborder="0"></iframe>
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import Select from "@/views/forms/Select.vue";


export default {
    name: 'Tables',
    components: {Select},
    data() {
        return {
            id: 0,
            cargando: true,
            url: '',
        };
    },
    mounted() {
        this.id = (typeof this.$route.params.id !== 'undefined') ? parseInt(this.$route.params.id) : 0;
        this.getItems();
    },
    methods: {
        getItems() {

            const self = this;
            toolbox.doAjax('POST', 'paneles/ver-panel', {
                    id: self.id
                },
                function (response) {
                    self.url = response.data.urlPanel;

                    setTimeout(function () {
                        self.cargando = false;
                    }, 1500)
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
    }
}
</script>
