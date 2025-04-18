<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Generar reporte</strong>
                </CCardHeader>
                <CCardBody>
                    <h5>Datos generales</h5>
                    <hr>
                    <div class="row">
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Selecciona reporte a generar</label>
                            </div>
                            <select class="form-control" v-model="reporte">
                                <option v-for="item in reportes" :value="item.id">{{item.nombre}}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha Inicial</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaIni">
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha Final</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaFin">
                        </div>
                    </div>
                    <div>
                        <div class="mt-4 text-end">
                            <button @click="guardar" class="btn btn-primary me-4">Generar reporte</button>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import Multiselect from '@vueform/multiselect'
import Select from "@/views/forms/Select.vue";


export default {
    name: 'Tables',
    components: {Select, Multiselect},
    data() {
        return {
            reportes: {},
            reporte: 0,
            fechaIni: new Date().toISOString().slice(0,10),
            fechaFin: new Date().toISOString().slice(0,10),
        };
    },
    mounted() {
        this.getItems();
    },
    methods: {
        getItems() {

            const self = this;
            toolbox.doAjax('GET', 'reportes/generar', {},
                function (response) {
                    self.reportes = response.data;
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        guardar() {

            const self = this;

            toolbox.doAjax('POST', 'reportes/generar', {
                    reporteId: self.reporte,
                    fechaIni: self.fechaIni,
                    fechaFin: self.fechaFin,
                },
                function (response) {
                    toolbox.alert(response.msg, 'success');
                    window.open(response.data.url);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
    }
}
</script>
