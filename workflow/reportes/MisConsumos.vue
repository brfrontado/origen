<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Mis consumos</strong>
                </CCardHeader>
                <CCardBody>
                    <h5>Reporte de tareas</h5>
                    <hr>
                    <div class="row">
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
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Tipo</label>
                            </div>
                            <select class="form-select" v-model="typeRpt">
                                <option value="nonvac">No incluir tareas vacías</option>
                                <option value="full">Incluir tareas vacías</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="mt-4 text-end">
                            <button @click="generar(false)" class="btn btn-primary me-2"><i class="fas fa-eye me-2"></i>Ver reporte</button>
                            <button @click="generar(true)" class="btn btn-primary me-4"><i class="fas fa-download me-2"></i>Descargar reporte</button>
                        </div>
                    </div>
                    <div v-if="Object.keys(conteoTareas).length > 0">
                        <h2>Total de tareas generadas: {{totalTareas}}</h2>
                        <highcharts :options="conteoTareas" class="mb-4 mt-4"></highcharts>
                        <br><br>
                        <div class="row">
                            <div class="col-12 col-sm-4">
                                <h2>Total de audio transcripciones:</h2>
                                <div class="consumoBadge">{{totalTranscripciones}}</div>
                            </div>
                            <div class="col-12 col-sm-4">

                            </div>
                            <div class="col-12 col-sm-4">

                            </div>
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
import dayjs from "dayjs";
import {Chart as highcharts} from "highcharts-vue";


export default {
    name: 'Tables',
    components: {highcharts, Select, Multiselect},
    data() {
        return {
            reportes: {},
            typeRpt: 'nonvac',
            reporte: 0,
            fechaIni: dayjs().startOf('month').add(0, 'day').format('YYYY-MM-DD'),
            fechaFin: dayjs().endOf('month').format('YYYY-MM-DD'),
            totalTareas: 0,
            conteoTareas: {},

            // transcripciones
            totalTranscripciones: 0,
        };
    },
    mounted() {

    },
    methods: {
        generar(descargar) {

            const self = this;
            if (!descargar) descargar = false;

            toolbox.doAjax('POST', 'reportes/mis-consumos', {
                    fechaIni: self.fechaIni,
                    fechaFin: self.fechaFin,
                    typeRpt: self.typeRpt,
                    descargar: descargar,
                },
                function (response) {

                    if (!descargar) {
                        self.totalTareas = response.data.total;
                        self.totalTranscripciones = response.data.transcripciones;
                        self.conteoTareas = {
                            chart: {
                                type: 'bar'
                            },
                            title: {
                                text: 'Tareas por flujo',
                                align: 'left'
                            },
                            xAxis: {
                                categories: ['Flujos'],
                                title: {
                                    text: null
                                },
                                gridLineWidth: 1,
                                lineWidth: 0
                            },
                            yAxis: {
                                min: 0,
                                title: {
                                    text: 'Conteo de tareas',
                                    align: 'high'
                                },
                                labels: {
                                    overflow: 'justify'
                                },
                                gridLineWidth: 0
                            },
                            tooltip: {
                                valueSuffix: ' tareas'
                            },
                            plotOptions: {
                                bar: {
                                    borderRadius: '10%',
                                    dataLabels: {
                                        enabled: true
                                    },
                                    groupPadding: 0.1
                                }
                            },
                            legend: {
                                layout: 'horizontal',
                                align: 'center',
                                verticalAlign: 'bottom',
                                floating: false,
                                borderWidth: 0,
                                shadow: false
                            },
                            credits: {
                                enabled: false
                            },
                            series: []
                        };

                        Object.keys(response.data.flujo).map(function (a) {

                            self.conteoTareas.series.push({
                                name: response.data.flujo[a].n,
                                data: [
                                    response.data.flujo[a].c
                                ]
                            });
                        })
                    }
                    else {
                        window.open(response.data.url);
                    }

                    toolbox.alert(response.msg, 'success');
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
    }
}
</script>
