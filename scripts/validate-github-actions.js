#!/usr/bin/env node

/**
 * GitHub Actions Configuration Validator
 * 
 * This script validates the GitHub Actions workflow configuration
 * to ensure it's properly structured and contains all required elements.
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');
const chalk = require('chalk');

class GitHubActionsValidator {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
    }

    /**
     * Validate the GitHub Actions workflow file
     */
    validate() {
        console.log(chalk.bold('🔍 GitHub Actions Workflow Validator\n'));

        try {
            // Check if workflow file exists
            if (!fs.existsSync(this.workflowPath)) {
                throw new Error('Workflow file not found: .github/workflows/release.yml');
            }

            // Read and parse YAML
            const content = fs.readFileSync(this.workflowPath, 'utf8');
            const workflow = yaml.load(content);

            // Validate workflow structure
            this.validateWorkflowStructure(workflow);
            this.validateTriggers(workflow);
            this.validatePermissions(workflow);
            this.validateJobs(workflow);
            this.validateSteps(workflow);

            console.log(chalk.green('\n✅ GitHub Actions workflow validation passed!'));
            console.log(chalk.blue('📋 Workflow Summary:'));
            console.log(`  • Name: ${workflow.name}`);
            console.log(`  • Triggers: ${Object.keys(workflow.on).join(', ')}`);
            console.log(`  • Jobs: ${Object.keys(workflow.jobs).length}`);
            console.log(`  • Steps: ${this.countSteps(workflow)}`);

            return true;

        } catch (error) {
            console.log(chalk.red(`❌ Validation failed: ${error.message}`));
            return false;
        }
    }

    /**
     * Validate basic workflow structure
     */
    validateWorkflowStructure(workflow) {
        const requiredFields = ['name', 'on', 'jobs'];
        
        for (const field of requiredFields) {
            if (!workflow[field]) {
                throw new Error(`Missing required field: ${field}`);
            }
        }

        console.log(chalk.green('✅ Basic workflow structure is valid'));
    }

    /**
     * Validate trigger configuration
     */
    validateTriggers(workflow) {
        if (!workflow.on.push || !workflow.on.push.tags) {
            throw new Error('Missing push trigger for tags');
        }

        const tagPatterns = workflow.on.push.tags;
        if (!Array.isArray(tagPatterns) || !tagPatterns.includes('v*')) {
            throw new Error('Missing or invalid tag pattern for version tags');
        }

        console.log(chalk.green('✅ Trigger configuration is valid'));
    }

    /**
     * Validate permissions
     */
    validatePermissions(workflow) {
        if (!workflow.permissions) {
            throw new Error('Missing permissions configuration');
        }

        if (workflow.permissions.contents !== 'write') {
            throw new Error('Missing or invalid contents permission (should be "write")');
        }

        console.log(chalk.green('✅ Permissions configuration is valid'));
    }

    /**
     * Validate jobs configuration
     */
    validateJobs(workflow) {
        if (!workflow.jobs.release) {
            throw new Error('Missing release job');
        }

        const releaseJob = workflow.jobs.release;
        
        if (releaseJob['runs-on'] !== 'ubuntu-latest') {
            throw new Error('Release job should run on ubuntu-latest');
        }

        if (!releaseJob.steps || !Array.isArray(releaseJob.steps)) {
            throw new Error('Release job missing steps');
        }

        console.log(chalk.green('✅ Jobs configuration is valid'));
    }

    /**
     * Validate workflow steps
     */
    validateSteps(workflow) {
        const releaseSteps = workflow.jobs.release.steps;
        const requiredSteps = [
            'Checkout Repository',
            'Setup Node.js',
            'Install Dependencies',
            'Build Plugin Package',
            'Create GitHub Release'
        ];

        for (const requiredStep of requiredSteps) {
            const stepExists = releaseSteps.some(step => 
                step.name && step.name.includes(requiredStep.split(' ').pop())
            );
            
            if (!stepExists) {
                throw new Error(`Missing required step: ${requiredStep}`);
            }
        }

        // Validate specific step configurations
        const checkoutStep = releaseSteps.find(step => step.uses && step.uses.includes('checkout'));
        if (!checkoutStep) {
            throw new Error('Missing checkout step');
        }

        const nodeStep = releaseSteps.find(step => step.uses && step.uses.includes('setup-node'));
        if (!nodeStep) {
            throw new Error('Missing Node.js setup step');
        }

        console.log(chalk.green('✅ Workflow steps are valid'));
    }

    /**
     * Count total steps in workflow
     */
    countSteps(workflow) {
        let totalSteps = 0;
        
        for (const jobName in workflow.jobs) {
            const job = workflow.jobs[jobName];
            if (job.steps && Array.isArray(job.steps)) {
                totalSteps += job.steps.length;
            }
        }
        
        return totalSteps;
    }
}

// CLI execution
if (require.main === module) {
    const validator = new GitHubActionsValidator();
    const isValid = validator.validate();
    process.exit(isValid ? 0 : 1);
}

module.exports = GitHubActionsValidator;
